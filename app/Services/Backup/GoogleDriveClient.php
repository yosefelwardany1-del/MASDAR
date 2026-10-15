<?php

namespace App\Services\Backup;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Google Drive v3 client for shipping nightly backups off-box.
 *
 * ── Why not an API key ────────────────────────────────────────────────────
 * An API key authorises anonymous READS of public files. Drive rejects every
 * write made with one ("Login Required", 401). Uploading requires OAuth.
 *
 * ── The two credential shapes ─────────────────────────────────────────────
 * OAuth (client id + secret + refresh token) uploads as the real user: the files
 * land in that person's Drive and consume their quota, so nothing has to be shared.
 * Its one failure mode is the consent screen — while the Cloud project sits in
 * "Testing", Google expires refresh tokens after seven days and the backups stop
 * silently. Publishing the consent screen fixes that permanently.
 *
 * A service account needs no browser, but owns NO Drive storage. It can only write
 * into a folder a real account has shared with its address, and the bytes count
 * against that owner. Google reports the unshared case as storageQuotaExceeded,
 * which reads like "you are full" — failedBecause() translates it.
 *
 * Deliberately hand-rolled rather than pulling in google/apiclient: this needs
 * three calls — token, upload, list/delete — and a focused implementation is
 * easier to audit than a 10 MB dependency tree.
 */
class GoogleDriveClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private ?string $token = null;

    /** @param array<string,mixed> $credentials Parsed service-account JSON. */
    public function __construct(
        private readonly array $credentials,
        private readonly int $timeout = 300,
    ) {
        // Either credential shape is valid; which one decides the token flow.
        $hasOauth = isset($this->credentials['refresh_token']);
        $hasServiceAccount = isset($this->credentials['client_email'], $this->credentials['private_key']);

        if (! $hasOauth && ! $hasServiceAccount) {
            throw new RuntimeException(
                'Google Drive credentials are not usable. Supply either an OAuth refresh token '
                .'(run `php artisan backup:google-auth`) or a service-account JSON key.'
            );
        }
    }

    /**
     * Build from a `backup.google_drive`-shaped config array — OAuth (client id + secret +
     * refresh token) preferred, else a service-account key given as raw JSON or a path.
     * Null when neither shape is configured. Other features may share the
     * backup's Drive connection through this rather than carrying credentials of their own.
     *
     * @param  array<string,mixed>  $cfg
     */
    public static function fromConfig(array $cfg, ?int $timeout = null): ?self
    {
        $timeout = $timeout ?? (int) ($cfg['timeout'] ?? 600);

        if (filled($cfg['refresh_token'] ?? null)) {
            return new self([
                'client_id' => (string) ($cfg['client_id'] ?? ''),
                'client_secret' => (string) ($cfg['client_secret'] ?? ''),
                'refresh_token' => (string) $cfg['refresh_token'],
            ], $timeout);
        }

        $raw = (string) ($cfg['credentials'] ?? '');
        if ($raw === '') {
            return null;
        }
        if (! str_starts_with(ltrim($raw), '{')) {
            if (! is_file($raw)) {
                throw new RuntimeException("Google credentials file not found at {$raw}.");
            }
            $raw = (string) file_get_contents($raw);
        }
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS is not valid JSON. Paste the service-account key file verbatim, or give an absolute path to it.');
        }

        return new self($parsed, $timeout);
    }

    /**
     * The service account's address, when that is the credential in use. Empty under
     * OAuth, where files are written as the signed-in user and nothing is shared.
     */
    public function accountEmail(): string
    {
        return (string) ($this->credentials['client_email'] ?? '');
    }

    /** True when uploading as a real user rather than a service account. */
    public function isOauth(): bool
    {
        return isset($this->credentials['refresh_token']);
    }

    /**
     * Upload a local file into a Drive folder. Returns the new file id.
     *
     * Uses a resumable session rather than a single multipart POST: a database dump
     * plus an uploads archive is routinely tens or hundreds of MB, and a multipart
     * upload has to hold the whole body in memory and restarts from zero on a blip.
     */
    public function upload(string $localPath, string $name, string $folderId): string
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException("Cannot read {$localPath} for upload.");
        }

        $start = $this->request()
            ->withBody(json_encode([
                'name' => $name,
                'parents' => [$folderId],
            ]) ?: '{}', 'application/json')
            ->withHeaders([
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post(self::UPLOAD.'/files?uploadType=resumable&supportsAllDrives=true');

        if (! $start->successful()) {
            throw new RuntimeException($this->failedBecause($start->status(), $start->body()));
        }

        $session = $start->header('Location');
        if (! $session) {
            throw new RuntimeException('Google Drive did not return an upload session URL.');
        }

        $handle = fopen($localPath, 'rb');
        try {
            $put = Http::timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $size,
                ])
                ->withBody($handle, 'application/octet-stream')
                ->put($session);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $put->successful()) {
            throw new RuntimeException($this->failedBecause($put->status(), $put->body()));
        }

        return (string) ($put->json('id') ?? '');
    }

    /** Find a sub-folder by name, or create it. Returns its id. */
    public function ensureFolder(string $name, string $parentId): string
    {
        if ($existing = $this->findFolder($name, $parentId)) {
            return $existing;
        }

        $res = $this->request()->post(self::API.'/files?supportsAllDrives=true', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ]);

        if (! $res->successful()) {
            throw new RuntimeException($this->failedBecause($res->status(), $res->body()));
        }

        return (string) $res->json('id');
    }

    public function findFolder(string $name, string $parentId): ?string
    {
        $escaped = str_replace("'", "\\'", $name);
        $res = $this->list(sprintf(
            "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            $escaped,
            $parentId
        ));

        return $res[0]['id'] ?? null;
    }

    /**
     * Sub-folders of a parent, as [['id' => ..., 'name' => ...], …].
     *
     * @return array<int,array{id:string,name:string}>
     */
    public function folders(string $parentId): array
    {
        return $this->list(sprintf(
            "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            $parentId
        ));
    }

    /** Permanently delete a file or folder (skips the trash, which also holds quota). */
    public function delete(string $fileId): void
    {
        $this->request()->delete(self::API.'/files/'.$fileId.'?supportsAllDrives=true');
    }

    /** @return array<int,array{id:string,name:string}> */
    private function list(string $query): array
    {
        $res = $this->request()->get(self::API.'/files', [
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 200,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        if (! $res->successful()) {
            throw new RuntimeException($this->failedBecause($res->status(), $res->body()));
        }

        return $res->json('files', []);
    }

    private function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    /**
     * An access token, by whichever credential shape was supplied.
     *
     * OAuth (client id + secret + refresh token) uploads as the REAL user, so the
     * files land in their own Drive and consume their own quota — no folder has to
     * be shared with anything. A service account has no storage of its own and needs
     * that sharing step, which is the usual reason these integrations fail.
     */
    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        return $this->token = isset($this->credentials['refresh_token'])
            ? $this->tokenFromRefreshToken()
            : $this->tokenFromServiceAccount();
    }

    /** Three-legged OAuth: swap the long-lived refresh token for a short-lived access token. */
    private function tokenFromRefreshToken(): string
    {
        $res = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->credentials['refresh_token'],
            'client_id' => $this->credentials['client_id'] ?? '',
            'client_secret' => $this->credentials['client_secret'] ?? '',
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            $err = (string) ($res->json('error') ?? '');

            throw new RuntimeException(match ($err) {
                'invalid_grant' => 'Google rejected the refresh token. The usual cause is an OAuth '
                    .'consent screen still in "Testing" — refresh tokens expire after 7 days there. '
                    .'Publish the consent screen, then run `php artisan backup:google-auth` again.',
                'invalid_client' => 'Google rejected the OAuth client. Check GOOGLE_DRIVE_CLIENT_ID '
                    .'and GOOGLE_DRIVE_CLIENT_SECRET match the credential in Google Cloud.',
                default => 'Google refused the OAuth credentials ('.$res->status().'): '
                    .mb_substr($res->body(), 0, 200),
            });
        }

        return (string) $res->json('access_token');
    }

    /** Two-legged: a self-signed JWT, for a service account. */
    private function tokenFromServiceAccount(): string
    {
        foreach (['client_email', 'private_key'] as $key) {
            if (empty($this->credentials[$key])) {
                throw new RuntimeException(
                    'Google credentials are incomplete: no refresh_token, and the service-account '
                    ."key is missing '{$key}'."
                );
            }
        }

        $now = time();
        $claim = [
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
        $payload = $this->base64Url(json_encode($claim) ?: '');
        $signature = '';

        if (! openssl_sign("{$header}.{$payload}", $signature, (string) $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException(
                'Could not sign the Google auth token — the private_key in the service-account '
                .'JSON is not a valid PEM key. Check that literal \n sequences were preserved.'
            );
        }

        $jwt = "{$header}.{$payload}.".$this->base64Url($signature);

        $res = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            throw new RuntimeException(
                'Google refused the service-account credentials ('.$res->status().'). '
                .'Check the JSON key is current and the Drive API is enabled for its project.'
            );
        }

        return $this->token = (string) $res->json('access_token');
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Turn Google's error bodies into something that names the actual fix. */
    private function failedBecause(int $status, string $body): string
    {
        $reason = (string) (json_decode($body, true)['error']['errors'][0]['reason'] ?? '');

        return match (true) {
            $reason === 'storageQuotaExceeded' => $this->isOauth()
                ? 'Google Drive is out of space — the signed-in account has no room left. Free some up, '
                .'or lower BACKUP_RETENTION_DAYS so older runs are pruned sooner.'
                : 'Google Drive rejected the upload for quota. A service account has no storage of its '
                .'own, so this usually means the target folder is not shared with '.$this->accountEmail()
                .' as an Editor — or the owning account is genuinely full.',
            $status === 404 => $this->isOauth()
                ? 'Google Drive could not find that folder. With the drive.file scope this app can only '
                .'reach folders it created itself — leave GOOGLE_DRIVE_FOLDER_ID empty and it will make '
                .'its own.'
                : 'Google Drive could not find the target folder. Check GOOGLE_DRIVE_FOLDER_ID, and that '
                .'the folder is shared with '.$this->accountEmail().'.',
            $status === 403 => 'Google Drive denied the request (403). Enable the Drive API for the service '
                ."account's project, and share the folder with ".$this->accountEmail().' as an Editor.',
            default => "Google Drive upload failed ({$status}): ".mb_substr($body, 0, 300),
        };
    }
}
