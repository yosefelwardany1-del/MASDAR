<?php

/*
|--------------------------------------------------------------------------
| Nightly backups
|--------------------------------------------------------------------------
| A backup has to leave the machine it is protecting. Render's container
| filesystem is EPHEMERAL — wiped on every redeploy, restart and (on the free
| plan) every wake-from-sleep — so a dump written to the 'local' disk is gone
| by the time anyone needs it.
|
| BACKUP_DISK therefore has to name an OFF-BOX destination. Any S3-compatible
| bucket works: Cloudflare R2, Backblaze B2, MinIO, AWS S3. It is the same
| bucket PRIVATE_DISK needs for user uploads, so setting one up covers both.
|
| backup:run refuses to write to a local disk in production rather than
| producing a file that will silently evaporate.
*/

return [

    // Destination disk (config/filesystems.php). MUST be off-box in production.
    'disk' => env('BACKUP_DISK', env('PRIVATE_DISK', 'local')),

    // Folder within that disk. One sub-folder per run, named by UTC date-time.
    'path' => env('BACKUP_PATH', 'backups'),

    // Delete runs older than this. Keep comfortably longer than the time it
    // typically takes someone to notice data is missing.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // What each nightly run captures.
    'include' => [
        // pg_dump / mysqldump of the primary connection, gzipped.
        'database' => (bool) env('BACKUP_DATABASE', true),

        // The durable user uploads — attachments, logos, scanned documents.
        // Restoring the database without these gives you rows pointing at files
        // that do not exist.
        'files' => (bool) env('BACKUP_FILES', true),
    ],

    // Disks whose contents count as "user uploads" for the files half.
    // Deliberately a list: attachments can be pointed at a different disk
    // (see config/attachments.php).
    'file_disks' => array_values(array_unique(array_filter([
        env('PRIVATE_DISK', 'local'),
        env('ATTACHMENTS_DISK'),
    ]))),

    // Google Drive destination.
    //
    // AN API KEY CANNOT WRITE TO DRIVE. Google accepts API keys only for anonymous
    // reads of public files and rejects every upload with 401. Writing needs OAuth.
    //
    // OAuth (preferred): uploads happen as the signed-in user, into their own Drive,
    // against their own quota — nothing has to be shared with anything.
    //   1. Google Cloud → APIs & Services → enable the Drive API
    //   2. Credentials → OAuth client ID → Desktop app, redirect http://localhost
    //   3. php artisan backup:google-auth   → prints the refresh token
    //   4. PUBLISH the OAuth consent screen. While it is in "Testing" Google expires
    //      refresh tokens after 7 days and the backups stop silently.
    //
    // Service account (alternative): no browser step, but it owns NO Drive storage,
    // so the target folder must be shared with its client_email as an Editor and the
    // bytes count against that owner's quota. Set GOOGLE_DRIVE_CREDENTIALS instead.
    'google_drive' => [
        'enabled' => (bool) env('BACKUP_GOOGLE_DRIVE', false),

        // --- OAuth (preferred) ---
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),

        // --- Service account (alternative) ---
        // Raw JSON, or an absolute path to the key file.
        'credentials' => env('GOOGLE_DRIVE_CREDENTIALS'),

        // Target folder. Leave the id empty under OAuth: the drive.file scope only
        // reaches files this app created, so it makes (and then reuses) its own
        // folder of this name at the Drive root.
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'folder_name' => env('GOOGLE_DRIVE_FOLDER_NAME', 'Alquds Backups'),

        // Seconds. A large uploads archive on a slow link needs room.
        'timeout' => (int) env('GOOGLE_DRIVE_TIMEOUT', 600),
    ],

    // Refuse to run when the destination is a local disk. Override only for a
    // local dry run — never in production, where it defeats the whole point.
    'allow_local_destination' => (bool) env('BACKUP_ALLOW_LOCAL', false),

    // Fail loudly: who hears about it when a nightly run does not complete.
    // Empty = log only. A backup that fails silently is worse than none, because
    // it buys false confidence.
    'notify' => array_filter(explode(',', (string) env('BACKUP_NOTIFY_EMAILS', ''))),
];
