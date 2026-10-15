<?php

namespace Tests\Feature\Platform;

use App\Console\Commands\RunBackup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The nightly backup.
 *
 * The property worth pinning is not "it writes a file" — it is that the command
 * REFUSES to write one where it cannot survive. Render's container filesystem is
 * wiped on every restart, so a dump on the local disk is a file that exists only
 * until the moment it is needed. A backup system that quietly produces those is
 * worse than none, because it buys confidence that is not there.
 */
class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_local_destination_in_production(): void
    {
        app()['env'] = 'production';
        config([
            'app.env' => 'production',
            'backup.disk' => 'local',
            'backup.allow_local_destination' => false,
        ]);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Refusing to back up to the local disk')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_disk_that_does_not_exist(): void
    {
        config(['backup.disk' => 'nowhere']);

        $this->artisan('backup:run')
            ->expectsOutputToContain('not defined in config/filesystems.php')
            ->assertExitCode(1);
    }

    public function test_a_local_destination_is_allowed_outside_production(): void
    {
        // Developers still need to be able to exercise it locally.
        Storage::fake('backups');
        config(['backup.disk' => 'backups', 'backup.include.files' => false]);

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertNotEmpty(
            Storage::disk('backups')->allFiles(),
            'a successful run must leave an artifact on the destination disk',
        );
    }

    public function test_it_writes_the_database_dump_and_the_uploads_side_by_side(): void
    {
        Storage::fake('backups');
        Storage::fake('local');
        Storage::disk('local')->put('contracts/agreement.pdf', 'pretend-pdf-bytes');

        config([
            'backup.disk' => 'backups',
            'backup.file_disks' => ['local'],
            'backup.include.files' => true,
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        $files = collect(Storage::disk('backups')->allFiles());
        $this->assertTrue(
            $files->contains(fn ($f) => str_contains($f, 'database.')),
            'the database dump should be stored',
        );
        $this->assertTrue(
            $files->contains(fn ($f) => str_ends_with($f, 'uploads.zip')),
            'the uploaded files should be stored alongside it — a database restored without '
            .'its files leaves rows pointing at documents that no longer exist',
        );
    }

    public function test_runs_older_than_the_retention_window_are_pruned(): void
    {
        Storage::fake('backups');
        config([
            'backup.disk' => 'backups',
            'backup.include.files' => false,
            'backup.retention_days' => 30,
        ]);

        $old = now()->utc()->subDays(45)->format('Y-m-d_His');
        $recent = now()->utc()->subDays(3)->format('Y-m-d_His');
        Storage::disk('backups')->put("backups/{$old}/database.sql.gz", 'old');
        Storage::disk('backups')->put("backups/{$recent}/database.sql.gz", 'recent');

        $this->artisan('backup:run')->assertExitCode(0);

        $this->assertFalse(
            Storage::disk('backups')->exists("backups/{$old}/database.sql.gz"),
            'a run past the retention window should be pruned',
        );
        $this->assertTrue(
            Storage::disk('backups')->exists("backups/{$recent}/database.sql.gz"),
            'a recent run must be kept',
        );
    }

    /**
     * A throwaway RSA key so the JWT signing path is exercised for real rather than
     * mocked away — a malformed key is one of the two ways this integration actually
     * fails in the wild (the other is an unshared folder).
     */
    private function fakeServiceAccount(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        return json_encode([
            'type' => 'service_account',
            'client_email' => 'alquds-backup@example.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]);
    }

    /** OAuth is the configured route: refresh token, no service account, no shared folder. */
    private function configureDrive(): void
    {
        config([
            'backup.disk' => 'local',
            'backup.include.files' => false,
            'backup.google_drive.enabled' => true,
            'backup.google_drive.client_id' => 'client-abc.apps.googleusercontent.com',
            'backup.google_drive.client_secret' => 'secret-xyz',
            'backup.google_drive.refresh_token' => 'refresh-123',
            'backup.google_drive.credentials' => null,
            'backup.google_drive.folder_id' => 'FOLDER123',
        ]);
    }

    public function test_it_uploads_to_google_drive(): void
    {
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            // No dated folder exists yet, so the run has to create one.
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []]),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'NEWFOLDER']),
            'www.googleapis.com/upload/drive/v3/*' => Http::response(['id' => 'FILE1'], 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=abc',
            ]),
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'uploadType=resumable'));
    }

    public function test_drive_enabled_but_unconfigured_fails_rather_than_pretending(): void
    {
        app()['env'] = 'production';
        config([
            'app.env' => 'production',
            'backup.disk' => 'local',
            'backup.google_drive.enabled' => true,
            'backup.google_drive.credentials' => null,
            'backup.google_drive.refresh_token' => null,
            'backup.google_drive.folder_id' => null,
        ]);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Google Drive is enabled but has no usable credential')
            ->assertExitCode(1);
    }

    public function test_google_drive_alone_satisfies_the_off_box_requirement(): void
    {
        // Drive IS off-box, so a local BACKUP_DISK must not block the run in production.
        app()['env'] = 'production';
        config(['app.env' => 'production']);
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []]),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'NEWFOLDER']),
            'www.googleapis.com/upload/drive/v3/*' => Http::response(['id' => 'FILE1'], 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=abc',
            ]),
        ]);

        $this->artisan('backup:run')->assertExitCode(0);
    }

    public function test_a_quota_failure_is_explained_not_just_reported(): void
    {
        // Google reports both "you are full" and "the folder was never shared" as
        // storageQuotaExceeded. The message has to say which one applies here.
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []]),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'NEWFOLDER']),
            'www.googleapis.com/upload/drive/v3/*' => Http::response([
                'error' => ['errors' => [['reason' => 'storageQuotaExceeded']]],
            ], 403),
        ]);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Google Drive is out of space')
            ->assertExitCode(1);
    }

    public function test_an_expired_refresh_token_names_the_real_cause(): void
    {
        // How OAuth Drive backups actually die: the consent screen was left in
        // "Testing", so Google expired the refresh token after 7 days. The raw error
        // is just "invalid_grant", which tells nobody anything.
        $this->configureDrive();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->artisan('backup:run')
            ->expectsOutputToContain('consent screen still in "Testing"')
            ->assertExitCode(1);
    }

    public function test_it_creates_its_own_folder_when_no_id_is_configured(): void
    {
        // drive.file only reaches files this app made, so a hand-made folder id would
        // 404. With no id set it must create its own at the Drive root.
        $this->configureDrive();
        config(['backup.google_drive.folder_id' => '']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []]),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'MADEFOLDER']),
            'www.googleapis.com/upload/drive/v3/*' => Http::response(['id' => 'FILE1'], 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=abc',
            ]),
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'drive/v3/files')
            && ($r->data()['name'] ?? null) === 'Alquds Backups');
    }

    /**
     * Regression: `pg_dump | gzip` in production reported only
     * "pg_dump produced an empty dump — refusing to store it."
     *
     * A shell pipeline exits with the status of its LAST command. When pg_dump died,
     * gzip still exited 0 having written a valid gzip of nothing, so the exit-code
     * check passed and pg_dump's own explanation was never read. The size check caught
     * the symptom and reported it as if it were the cause.
     */
    public function test_the_postgres_dump_is_not_a_shell_pipeline(): void
    {
        $process = $this->invoke('postgresProcess', [
            '/tmp/database.sql.gz', 'db.example.com', 5432, 'erp', 'erpuser',
        ]);

        $line = $process->getCommandLine();

        $this->assertStringNotContainsString('|', $line, 'a pipeline hides pg_dump\'s exit status');
        $this->assertStringNotContainsString('gzip', $line);
        $this->assertStringContainsString('--compress', $line, 'pg_dump must do its own compression');
        $this->assertStringContainsString('--file=', $line);
    }

    /**
     * The other half of the same production failure: the connection details never
     * reached pg_dump at all.
     *
     * The command was built with Process::fromShellCommandline('… --host=${:PGHOST} …'),
     * but Symfony only substitutes that placeholder when it is QUOTED — its pattern is
     * /"\$\{:(\w+)\}"/ — and these were bare. So the literal ${:PGHOST} went to the shell,
     * which expanded it to nothing, and pg_dump was invoked with --host= --port=
     * --username= and fell back to a local unix socket that does not exist in the
     * container. It wrote nothing, gzip dutifully compressed the nothing, and the
     * pipeline reported gzip's success.
     *
     * Passing real values as separate argv entries is what makes this unrepresentable.
     */
    public function test_the_connection_details_reach_pg_dump_as_real_values(): void
    {
        $process = $this->invoke('postgresProcess', [
            '/tmp/database.sql.gz', 'ep-example.us-west-2.aws.neon.tech', 5432, 'neondb', 'neondb_owner',
        ]);

        $line = $process->getCommandLine();

        $this->assertStringNotContainsString('${:', $line, 'an unsubstituted placeholder means no host at all');
        $this->assertStringContainsString('ep-example.us-west-2.aws.neon.tech', $line);
        $this->assertStringContainsString('neondb_owner', $line);
        $this->assertStringContainsString('neondb', $line);
    }

    /**
     * Neon hands the app `…/neondb?sslmode=require&channel_binding=require`. Laravel folds
     * that query into the PDO options; the dump has to negotiate on the same terms or it
     * connects on weaker ones than the server will accept.
     */
    public function test_the_dump_inherits_the_connection_tls_terms(): void
    {
        [, , , , , $params] = $this->invoke('resolveDsn', [
            ['url' => 'postgresql://u:p@host.neon.tech/neondb?sslmode=require&channel_binding=require'],
            5432,
        ]);

        $env = $this->invoke('libpqEnv', [[], $params, 'secret']);

        $this->assertSame('require', $env['PGSSLMODE']);
        $this->assertSame('require', $env['PGCHANNELBINDING']);
        $this->assertSame('secret', $env['PGPASSWORD']);
    }

    /**
     * The connection's `options` is Laravel's PDO attribute ARRAY (persistent connections,
     * no server-side prepares), not libpq's `options` string. Cast to a string it was
     * "Array to string conversion" — every PostgreSQL dump failed before pg_dump started.
     */
    public function test_pdo_options_on_the_connection_never_reach_libpq(): void
    {
        $env = $this->invoke('libpqEnv', [
            ['options' => [\PDO::ATTR_PERSISTENT => true], 'sslmode' => 'prefer'],
            [],
            'secret',
        ]);

        $this->assertArrayNotHasKey('PGOPTIONS', $env);
        $this->assertSame('prefer', $env['PGSSLMODE']);

        // libpq's own `options`, given in the URL, still goes through.
        $env = $this->invoke('libpqEnv', [['options' => [\PDO::ATTR_PERSISTENT => true]], ['options' => '-c search_path=public'], 'secret']);
        $this->assertSame('-c search_path=public', $env['PGOPTIONS']);
    }

    public function test_discrete_config_still_resolves_without_a_url(): void
    {
        [$host, $port, $db, $user, $pass, $params] = $this->invoke('resolveDsn', [
            ['host' => 'db.internal', 'port' => 6543, 'database' => 'erp', 'username' => 'erp', 'password' => 'pw'],
            5432,
        ]);

        $this->assertSame(['db.internal', 6543, 'erp', 'erp', 'pw', []], [$host, $port, $db, $user, $pass, $params]);
    }

    public function test_a_failed_dump_reports_what_the_tool_actually_said(): void
    {
        // Exit 0 with an empty output file and a complaint on stderr — precisely the
        // shape the pipeline used to produce, and the shape that told us nothing.
        $process = new Process(
            ['sh', '-c', 'echo "pg_dump: error: server version 17.2; pg_dump version 15.8" >&2; exit 0']
        );
        $process->run();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('server version 17.2');

        $this->invoke('assertDump', [$process, '/tmp/definitely-not-written.sql.gz', 'pg_dump']);
    }

    public function test_the_error_hint_names_the_next_thing_to_do(): void
    {
        $cases = [
            'sh: pg_dump: not found' => 'postgresql-client',
            'pg_dump: error: server version 17.2' => 'older than the database server',
            'FATAL: password authentication failed' => 'credentials were refused',
            'FATAL: database "erp" does not exist' => 'does not match a database',
            'permission denied for table payrolls' => 'may not read every table',
        ];

        foreach ($cases as $stderr => $expected) {
            $this->assertStringContainsString(
                $expected,
                $this->invoke('dumpHint', [$stderr, 'pg_dump']),
                "stderr '{$stderr}' should point somewhere useful",
            );
        }
    }

    /** The dump internals are private on purpose; these are the two that earn a direct test. */
    private function invoke(string $method, array $args): mixed
    {
        $command = new RunBackup;
        $ref = new \ReflectionMethod($command, $method);

        return $ref->invokeArgs($command, $args);
    }

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'backup:run'));

        $this->assertCount(1, $events, 'backup:run should be scheduled exactly once');
        $this->assertSame('15 2 * * *', $events->first()->expression);
    }
}
