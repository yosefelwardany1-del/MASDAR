<?php

return [

    /*
    | Attachments hold sensitive documents (passports, IDs, customs papers). They live on a
    | PRIVATE disk and are served only through the authorised download controller.
    | Production sets PRIVATE_DISK=s3 (Cloudflare R2): the container disk is wiped on
    | every deploy.
    */
    'disk' => env('ATTACHMENTS_DISK', env('PRIVATE_DISK', 'local')),

    'max_size_kb' => (int) env('ATTACHMENTS_MAX_SIZE_KB', 10240), // 10 MB

    'max_files_per_request' => (int) env('ATTACHMENTS_MAX_FILES', 20),

    // Whitelist, fail-closed: documents and images only.
    'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'],

    /*
    | Attachable types (url segment => model class). A model becomes attachable by using
    | HasAttachments AND being listed here. Each sub-project registers its own.
    */
    'attachables' => [],

];
