<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-time helper: turn a Google OAuth client into the refresh token the nightly
 * backup uses.
 *
 * Run it once, paste the result into GOOGLE_DRIVE_REFRESH_TOKEN, and the nightly job
 * never needs a browser again. Uploads then happen as the signed-in user, into their
 * own Drive and against their own quota — which is why OAuth avoids the service
 * account's "no storage of its own" problem entirely.
 *
 * THE ONE THING THAT BREAKS THIS LATER: while the OAuth consent screen is in
 * "Testing" publishing status, Google expires refresh tokens after SEVEN DAYS. The
 * backups then stop, quietly, a week after anyone last looked. Publish the consent
 * screen (Google Cloud → APIs & Services → OAuth consent screen → Publish app)
 * before relying on this.
 */
class GoogleDriveAuth extends Command
{
    protected $signature = 'backup:google-auth
        {--client-id= : OAuth client id (defaults to GOOGLE_DRIVE_CLIENT_ID)}
        {--client-secret= : OAuth client secret (defaults to GOOGLE_DRIVE_CLIENT_SECRET)}';

    protected $description = 'Obtain a Google Drive refresh token for the nightly backup (run once).';

    /**
     * Google retired the out-of-band redirect in 2022, so the workable CLI flow is a
     * localhost redirect: the browser lands on a page that will not load, and the code
     * is sitting in the address bar to copy.
     */
    private const REDIRECT = 'http://localhost';

    public function handle(): int
    {
        $clientId = (string) ($this->option('client-id') ?: config('backup.google_drive.client_id'));
        $clientSecret = (string) ($this->option('client-secret') ?: config('backup.google_drive.client_secret'));

        if ($clientId === '' || $clientSecret === '') {
            $this->error('Need an OAuth client id and secret.');
            $this->newLine();
            $this->newLine();
            $this->line('  1. console.cloud.google.com → pick or create a project');
            $this->line('  2. APIs & Services → Library → enable "Google Drive API"');
            $this->line('  3. APIs & Services → OAuth consent screen → configure it');
            $this->line('  4. Credentials → Create credentials → OAuth client ID');
            $this->line('     Application type: Desktop app');
            $this->newLine();
            $this->line('A Desktop app client shows no redirect-URI field — that is expected.');
            $this->line('Google allows localhost redirects for that type automatically.');
            $this->newLine();
            $this->line('Then pass them straight in:');
            $this->line('  php artisan backup:google-auth --client-id=… --client-secret=…');

            return self::FAILURE;
        }

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            // offline + consent together are what guarantee a refresh_token comes back.
            // Without prompt=consent Google omits it on every grant after the first,
            // and the failure only shows up as a missing token here.
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        $this->newLine();
        $this->line('1. Open this URL and approve access:');
        $this->newLine();
        $this->line($url);
        $this->newLine();
        $this->line('2. Google will redirect to a localhost page that fails to load — that is expected.');
        $this->line('   Copy the "code" parameter out of the address bar.');
        $this->newLine();

        $code = trim((string) $this->ask('3. Paste the code'));
        if ($code === '') {
            $this->error('No code given.');

            return self::FAILURE;
        }

        $res = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => self::REDIRECT,
            'grant_type' => 'authorization_code',
        ]);

        if (! $res->successful()) {
            $this->error('Google rejected the code: '.mb_substr($res->body(), 0, 300));
            $this->line('Codes are single-use and expire within minutes — try the URL again for a fresh one.');

            return self::FAILURE;
        }

        $refresh = (string) ($res->json('refresh_token') ?? '');
        if ($refresh === '') {
            $this->error('Google returned no refresh token.');
            $this->line('That happens when the account has already granted this client. Revoke it at');
            $this->line('https://myaccount.google.com/permissions and run this again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Add these to the environment:');
        $this->newLine();
        $this->line('BACKUP_GOOGLE_DRIVE=true');
        $this->line('GOOGLE_DRIVE_CLIENT_ID='.$clientId);
        $this->line('GOOGLE_DRIVE_CLIENT_SECRET='.$clientSecret);
        $this->line('GOOGLE_DRIVE_REFRESH_TOKEN='.$refresh);
        $this->newLine();
        $this->warn('Publish the OAuth consent screen before relying on this. While it is in');
        $this->warn('"Testing", Google expires refresh tokens after 7 days and the backups stop silently.');

        return self::SUCCESS;
    }
}
