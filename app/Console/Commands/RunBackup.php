<?php

namespace App\Console\Commands;

use App\Services\Backup\GoogleDriveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Nightly backup: a database dump plus the durable user uploads, written to an
 * OFF-BOX destination.
 *
 * The one rule this command exists to enforce: a backup that lives on the machine
 * it is protecting is not a backup. Render's container filesystem is wiped on every
 * redeploy, restart and — on the free plan — every wake-from-sleep, so writing a dump
 * to the 'local' disk produces a file that is gone before anyone needs it. In
 * production this refuses to run rather than manufacture that false comfort.
 *
 * Restore is the other half and is deliberately manual — see README.md (Backups).
 * An untested restore is a hope, not a plan.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run
        {--database-only : Skip the uploaded files}
        {--files-only : Skip the database dump}
        {--keep-local : Do not delete the working copy (debugging only)}';

    protected $description = 'Back up the database and user uploads to the configured off-box destination.';

    /** Working directory for the dump before it is streamed to the destination. */
    private string $work;

    public function handle(): int
    {
        $disk = (string) config('backup.disk');
        $useDisk = $this->destinationProblem($disk) === null;
        $useDrive = $this->driveConfigured();

        // At least one destination has to be somewhere this container is not.
        if (! $useDisk && ! $useDrive) {
            $this->error($this->destinationProblem($disk) ?? 'No backup destination configured.');
            if (! $useDrive && config('backup.google_drive.enabled')) {
                $this->error('Google Drive is enabled but has no usable credential. Either run '
                    .'`php artisan backup:google-auth` and set GOOGLE_DRIVE_CLIENT_ID / '
                    .'GOOGLE_DRIVE_CLIENT_SECRET / GOOGLE_DRIVE_REFRESH_TOKEN, or set '
                    .'GOOGLE_DRIVE_CREDENTIALS to a service-account JSON key.');
            }

            return self::FAILURE;
        }

        $stamp = now()->utc()->format('Y-m-d_His');
        $folder = trim((string) config('backup.path', 'backups'), '/').'/'.$stamp;
        $this->work = storage_path('app/backup-tmp/'.$stamp);
        @mkdir($this->work, 0755, true);

        $artifacts = [];

        try {
            if (! $this->option('files-only') && config('backup.include.database', true)) {
                $artifacts[] = $this->dumpDatabase();
            }

            if (! $this->option('database-only') && config('backup.include.files', true)) {
                if ($archive = $this->archiveUploads()) {
                    $artifacts[] = $archive;
                }
            }

            if ($artifacts === []) {
                $this->warn('Nothing selected to back up.');

                return self::FAILURE;
            }

            $shipped = [];

            if ($useDisk) {
                foreach ($artifacts as $file) {
                    $this->upload($disk, $folder, $file);
                }
                $this->prune($disk);
                $shipped[] = "{$disk}:{$folder}";
            }

            if ($useDrive) {
                $this->shipToDrive($artifacts, $stamp);
                $shipped[] = 'google-drive';
            }

            $total = array_sum(array_map(fn ($f) => filesize($f) ?: 0, $artifacts));
            $this->info(sprintf('Backup complete → %s (%s)', implode(' + ', $shipped), $this->human($total)));
            Log::info('Backup completed', ['to' => $shipped, 'bytes' => $total]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Loud on purpose. A silent backup failure is worse than no backup at all,
            // because it buys confidence that is not there.
            $this->error('Backup FAILED: '.$e->getMessage());
            Log::error('Backup failed', ['error' => $e->getMessage()]);
            $this->notifyFailure($e);

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-local')) {
                $this->cleanWorkDir();
            }
        }
    }

    /**
     * Why this destination cannot be trusted, or null when it can.
     *
     * Checked before any work is done so a misconfigured deployment fails fast and
     * visibly, instead of writing a dump onto a disk that is about to be erased.
     */
    private function destinationProblem(string $disk): ?string
    {
        // Resolve rather than only reading config: a disk can also be registered at
        // runtime (custom drivers, Storage::fake in tests), and those have no config
        // entry to inspect.
        try {
            $filesystem = Storage::disk($disk);
        } catch (Throwable) {
            return "Backup disk '{$disk}' is not defined in config/filesystems.php.";
        }

        $driver = config("filesystems.disks.{$disk}.driver");
        $isLocal = $driver === 'local'
            || ($driver === null && $filesystem->getAdapter() instanceof LocalFilesystemAdapter);

        if (! $isLocal) {
            return null;
        }

        if (config('backup.allow_local_destination') || ! app()->isProduction()) {
            return null;
        }

        return "Refusing to back up to the local disk: this container's filesystem is wiped on "
             .'every restart, so the backup would not survive to be used. Set BACKUP_DISK to an '
             .'off-box destination (any S3-compatible bucket — R2, B2, MinIO, S3). '
             .'See README.md (Backups).';
    }

    /** Dump the primary connection. Returns the path to the gzipped dump. */
    private function dumpDatabase(): string
    {
        $connection = config('database.default');
        $cfg = config("database.connections.{$connection}");
        $driver = $cfg['driver'] ?? '';

        $this->line("Dumping database ({$driver})…");

        return match ($driver) {
            'pgsql' => $this->dumpPostgres($cfg),
            'mysql', 'mariadb' => $this->dumpMysql($cfg),
            'sqlite' => $this->dumpSqlite($cfg),
            default => throw new \RuntimeException("No dump strategy for driver '{$driver}'."),
        };
    }

    private function dumpPostgres(array $cfg): string
    {
        $out = $this->work.'/database.sql.gz';
        [$host, $port, $db, $user, $pass, $params] = $this->resolveDsn($cfg, 5432);

        $process = $this->postgresProcess($out, $host, $port, $db, $user);
        // Password goes through the environment, never the command line, so it cannot
        // leak into a process listing or an exception message.
        $process->run(null, $this->libpqEnv($cfg, $params, $pass));

        // Some builds append .gz themselves when compressing. Accept either name
        // rather than declare a perfectly good dump missing.
        if (! is_file($out) && is_file($out.'.gz')) {
            rename($out.'.gz', $out);
        }

        $this->assertDump($process, $out, 'pg_dump');

        return $out;
    }

    /**
     * Deliberately NOT `pg_dump | gzip`.
     *
     * A shell pipeline reports the exit status of its LAST command, so when pg_dump
     * dies — binary missing from the image, credentials refused, server newer than the
     * client — gzip still succeeds and writes a valid gzip of nothing. The command then
     * looks successful and the only symptom is a suspiciously small file, with pg_dump's
     * actual complaint thrown away. Letting pg_dump do its own compression (--compress)
     * means the exit code and stderr we check are its own.
     *
     * --no-owner / --no-acl so the dump restores into a differently-named role, which is
     * what a restore into a fresh database always is.
     */
    private function postgresProcess(string $out, string $host, int $port, string $db, string $user): Process
    {
        $process = new Process([
            'pg_dump',
            '--no-owner',
            '--no-acl',
            '--format=plain',
            '--compress=9',
            '--file='.$out,
            '--host='.$host,
            '--port='.$port,
            '--username='.$user,
            $db,
        ]);
        $process->setTimeout(3600);

        return $process;
    }

    /** Same no-pipeline reasoning as postgresProcess(); mysqldump compresses via --result-file + gzip here. */
    private function dumpMysql(array $cfg): string
    {
        $plain = $this->work.'/database.sql';
        [$host, $port, $db, $user, $pass] = $this->resolveDsn($cfg, 3306);

        $process = new Process([
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--result-file='.$plain,
            '--host='.$host,
            '--port='.$port,
            '--user='.$user,
            $db,
        ]);
        $process->setTimeout(3600);
        $process->run(null, ['MYSQL_PWD' => $pass]);

        $this->assertDump($process, $plain, 'mysqldump');

        return $this->gzip($plain);
    }

    /** Compress in fixed-size chunks: a large dump must not be held in memory to be gzipped. */
    private function gzip(string $path): string
    {
        $out = $path.'.gz';
        $in = fopen($path, 'rb');
        $gz = gzopen($out, 'wb9');

        while (! feof($in)) {
            gzwrite($gz, (string) fread($in, 1024 * 512));
        }

        fclose($in);
        gzclose($gz);
        @unlink($path);

        return $out;
    }

    /**
     * SQLite: a LOGICAL dump (schema + inserts) rather than a file copy.
     *
     * Copying the file would be faster, but it only works for a file-backed database
     * and silently has nothing to copy for an in-memory one. A logical dump restores
     * into any SQLite database and is the same shape as the Postgres/MySQL output,
     * so the restore procedure does not change with the driver.
     */
    private function dumpSqlite(array $cfg): string
    {
        $out = $this->work.'/database.sql.gz';

        $objects = DB::select(
            "SELECT name, type, sql FROM sqlite_master
             WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC, name"
        );

        $sql = "-- SQLite logical dump\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        foreach ($objects as $o) {
            $sql .= $o->sql.";\n";

            if ($o->type !== 'table') {
                continue;
            }

            // Chunked so a large table cannot exhaust memory building one string.
            DB::table($o->name)->orderByRaw('1')->chunk(500, function ($rows) use (&$sql, $o) {
                foreach ($rows as $row) {
                    $cols = array_keys((array) $row);
                    $vals = array_map(
                        fn ($v) => $v === null ? 'NULL' : "'".str_replace("'", "''", (string) $v)."'",
                        array_values((array) $row)
                    );
                    $sql .= 'INSERT INTO "'.$o->name.'" ("'.implode('","', $cols).'") VALUES ('
                          .implode(',', $vals).");\n";
                }
            });
        }

        $sql .= "COMMIT;\nPRAGMA foreign_keys=ON;\n";
        file_put_contents($out, gzencode($sql, 9));

        return $out;
    }

    /**
     * host/port/database/user/password/params, whether they come from discrete config
     * keys or a single DB_URL (which is how Render and Neon hand them over).
     *
     * The sixth element is the URL's query string. It is not decoration: Neon issues
     * `…/neondb?sslmode=require&channel_binding=require`, and Laravel's own URL parser
     * folds those into the connection options, so PDO connects over TLS. Dropping them
     * here left pg_dump on libpq's default sslmode=prefer with no channel binding —
     * which the same server refuses. The app connected; the backup did not.
     *
     * @return array{0:string,1:int,2:string,3:string,4:string,5:array<string,string>}
     */
    private function resolveDsn(array $cfg, int $defaultPort): array
    {
        if (! empty($cfg['url'])) {
            $p = parse_url((string) $cfg['url']);

            $params = [];
            parse_str($p['query'] ?? '', $params);

            return [
                $p['host'] ?? '127.0.0.1',
                (int) ($p['port'] ?? $defaultPort),
                ltrim($p['path'] ?? '', '/'),
                urldecode($p['user'] ?? ''),
                urldecode($p['pass'] ?? ''),
                array_map('strval', $params),
            ];
        }

        return [
            (string) ($cfg['host'] ?? '127.0.0.1'),
            (int) ($cfg['port'] ?? $defaultPort),
            (string) ($cfg['database'] ?? ''),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? ''),
            [],
        ];
    }

    /**
     * The libpq environment for a connection — everything beyond host/port/user that the
     * server may insist on before it will talk.
     *
     * Sourced from the URL query first, then the connection's own config, so pg_dump
     * negotiates on exactly the terms PDO does. Everything goes through the environment
     * rather than the command line, so no credential reaches a process listing.
     *
     * @param  array<string,string>  $params
     * @return array<string,string>
     */
    private function libpqEnv(array $cfg, array $params, string $password): array
    {
        $env = ['PGPASSWORD' => $password];

        $map = [
            'sslmode' => 'PGSSLMODE',
            'sslrootcert' => 'PGSSLROOTCERT',
            'sslcert' => 'PGSSLCERT',
            'sslkey' => 'PGSSLKEY',
            'channel_binding' => 'PGCHANNELBINDING',
            'options' => 'PGOPTIONS',
        ];

        foreach ($map as $key => $var) {
            $value = $params[$key] ?? $cfg[$key] ?? null;
            // The connection's own `options` is Laravel's PDO attribute array, not
            // libpq's options string: only a scalar is a libpq value.
            if (is_scalar($value) && filled($value)) {
                $env[$var] = (string) $value;
            }
        }

        return $env;
    }

    /** A dump that "succeeded" but produced nothing is a failure worth catching here. */
    private function assertDump(Process $process, string $out, string $tool): void
    {
        $err = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("{$tool} failed: ".($err ?: 'unknown error').$this->dumpHint($err, $tool));
        }

        // Both halves matter. The exit code catches a tool that died; the size catches one
        // that exited 0 having written nothing, which is what a truncated or filtered-out
        // dump looks like. Either way, say what the tool said — a bare "empty dump" leaves
        // whoever is on call with no thread to pull.
        if (! is_file($out) || filesize($out) < 100) {
            throw new \RuntimeException(
                "{$tool} produced an empty dump — refusing to store it."
                .($err !== '' ? " {$tool} said: {$err}" : " {$tool} said nothing at all.")
                .$this->dumpHint($err, $tool)
            );
        }
    }

    /** Turn the tool's own words into the next thing to actually do. */
    private function dumpHint(string $err, string $tool): string
    {
        return match (true) {
            str_contains($err, 'not found'),
            str_contains($err, 'No such file') => " ({$tool} is not installed in this image — add postgresql-client to the Dockerfile and redeploy)",
            str_contains($err, 'server version') => " (the installed {$tool} is older than the database server — the image needs a newer postgresql-client)",
            str_contains($err, 'authentication'),
            str_contains($err, 'password') => ' (the database credentials were refused — check DB_URL)',
            str_contains($err, 'SSL'),
            str_contains($err, 'sslmode') => ' (the server demands TLS — set PGSSLMODE=require)',
            str_contains($err, 'does not exist') => ' (the database name in DB_URL does not match a database on that server)',
            str_contains($err, 'permission denied') => ' (this database role may not read every table — a dump needs a role that can)',
            default => '',
        };
    }

    /**
     * Zip the durable upload disks. Returns null when there is nothing to archive,
     * which is itself worth saying out loud: it usually means PRIVATE_DISK was never
     * pointed off-box and the files have already been lost to a restart.
     */
    private function archiveUploads(): ?string
    {
        $disks = (array) config('backup.file_disks', []);
        $out = $this->work.'/uploads.zip';

        $zip = new ZipArchive;
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the uploads archive.');
        }

        $count = 0;
        foreach ($disks as $name) {
            if (config("filesystems.disks.{$name}") === null) {
                continue;
            }

            $storage = Storage::disk($name);
            foreach ($storage->allFiles() as $file) {
                if (str_starts_with($file, 'backup-tmp/')) {
                    continue; // never nest a backup inside a backup
                }
                if ($stream = $storage->readStream($file)) {
                    $zip->addFromString("{$name}/{$file}", (string) stream_get_contents($stream));
                    fclose($stream);
                    $count++;
                }
            }
        }
        $zip->close();

        if ($count === 0) {
            @unlink($out);
            $this->warn('No uploaded files found to archive — check that PRIVATE_DISK points off-box.');

            return null;
        }

        $this->line("Archived {$count} uploaded file(s).");

        return $out;
    }

    private function upload(string $disk, string $folder, string $file): void
    {
        $stream = fopen($file, 'rb');
        Storage::disk($disk)->put($folder.'/'.basename($file), $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $this->line('  → '.basename($file).' ('.$this->human((int) filesize($file)).')');
    }

    /** Drop runs older than the retention window. */
    private function prune(string $disk): void
    {
        $days = (int) config('backup.retention_days', 30);
        if ($days <= 0) {
            return;
        }

        $root = trim((string) config('backup.path', 'backups'), '/');
        $cutoff = now()->utc()->subDays($days);
        $removed = 0;

        foreach (Storage::disk($disk)->directories($root) as $dir) {
            // Folder names are UTC Y-m-d_His — parse rather than trust mtime, which
            // object stores do not preserve the way a filesystem does.
            $name = basename($dir);
            $when = \DateTimeImmutable::createFromFormat('Y-m-d_His', $name, new \DateTimeZone('UTC'));
            if ($when === false) {
                continue;
            }
            if ($when->getTimestamp() < $cutoff->getTimestamp()) {
                Storage::disk($disk)->deleteDirectory($dir);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("Pruned {$removed} backup(s) older than {$days} days.");
        }
    }

    /** True when Drive is switched on AND has a usable credential. */
    private function driveConfigured(): bool
    {
        if (! config('backup.google_drive.enabled')) {
            return false;
        }

        $oauth = filled(config('backup.google_drive.client_id'))
            && filled(config('backup.google_drive.client_secret'))
            && filled(config('backup.google_drive.refresh_token'));

        return $oauth || filled(config('backup.google_drive.credentials'));
    }

    /**
     * Upload this run's artifacts into a dated sub-folder of the shared Drive folder,
     * then prune Drive on the same retention window as the disk.
     */
    private function shipToDrive(array $artifacts, string $stamp): void
    {
        $drive = new GoogleDriveClient(
            $this->driveCredentials(),
            (int) config('backup.google_drive.timeout', 600),
        );

        // Under OAuth the drive.file scope can only see files this app created, so a
        // hand-made folder's id would 404. With no id configured, make our own once
        // and reuse it from then on.
        $root = (string) config('backup.google_drive.folder_id');
        if ($root === '') {
            $root = $drive->ensureFolder((string) config('backup.google_drive.folder_name', 'Alquds Backups'), 'root');
        }

        $folder = $drive->ensureFolder($stamp, $root);

        foreach ($artifacts as $file) {
            $drive->upload($file, basename($file), $folder);
            $this->line('  → google-drive/'.$stamp.'/'.basename($file));
        }

        $this->pruneDrive($drive, $root);
    }

    /** Whichever credential shape is configured — OAuth preferred. */
    private function driveCredentials(): array
    {
        if (filled(config('backup.google_drive.refresh_token'))) {
            return [
                'client_id' => (string) config('backup.google_drive.client_id'),
                'client_secret' => (string) config('backup.google_drive.client_secret'),
                'refresh_token' => (string) config('backup.google_drive.refresh_token'),
            ];
        }

        $raw = (string) config('backup.google_drive.credentials');

        if (! str_starts_with(ltrim($raw), '{')) {
            if (! is_file($raw)) {
                throw new \RuntimeException("Google credentials file not found at {$raw}.");
            }
            $raw = (string) file_get_contents($raw);
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new \RuntimeException(
                'GOOGLE_DRIVE_CREDENTIALS is not valid JSON. Paste the service-account key file '
                .'verbatim, or give an absolute path to it.'
            );
        }

        return $parsed;
    }

    private function pruneDrive(GoogleDriveClient $drive, string $root): void
    {
        $days = (int) config('backup.retention_days', 30);
        if ($days <= 0) {
            return;
        }

        $cutoff = now()->utc()->subDays($days);
        $removed = 0;

        foreach ($drive->folders($root) as $folder) {
            $when = \DateTimeImmutable::createFromFormat('Y-m-d_His', $folder['name'], new \DateTimeZone('UTC'));
            if ($when === false) {
                continue; // not one of ours — leave anything else in the folder alone
            }
            if ($when->getTimestamp() < $cutoff->getTimestamp()) {
                $drive->delete($folder['id']);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("Pruned {$removed} Drive backup(s) older than {$days} days.");
        }
    }

    private function notifyFailure(Throwable $e): void
    {
        $to = (array) config('backup.notify', []);
        if ($to === []) {
            return;
        }

        try {
            Mail::raw(
                "The nightly backup did not complete.\n\n"
                .$e->getMessage()."\n\n"
                .'Host: '.config('app.url'),
                fn ($m) => $m->to($to)->subject('[Alquds] Nightly backup FAILED'),
            );
        } catch (Throwable) {
            // Mail being down must not mask the original failure in the log.
        }
    }

    private function cleanWorkDir(): void
    {
        if (! is_dir($this->work)) {
            return;
        }
        foreach (glob($this->work.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->work);
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
