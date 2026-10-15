<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | The ResourceSchema classes the Import / Export screen offers. Each
    | sub-project lists its own; none ship with the platform.
    |
    */
    'resources' => [],

    /*
    |--------------------------------------------------------------------------
    | Queue threshold
    |--------------------------------------------------------------------------
    |
    | Row count at or above which an import is handed to a queued worker instead
    | of being processed inside the request. UNDER the threshold nothing changes:
    | the import runs synchronously and returns its result in the response. A
    | small file must not acquire a new class of failure (a worker that is not
    | running) to fix a problem it never had.
    |
    | The PREVIEW queues on the same threshold as the commit. A 5,000-row file
    | times out on validation alone, and validation is where the person most
    | needs an answer; preview and commit are the same processRows() call with
    | $commit flipped, so treating them differently would simply move the
    | timeout rather than remove it.
    |
    */
    'queue_threshold' => (int) env('IO_QUEUE_THRESHOLD', 200),

    /*
    |--------------------------------------------------------------------------
    | Upload storage
    |--------------------------------------------------------------------------
    |
    | A queued import cannot read from the PHP temp file — the request is long
    | gone by the time the worker starts. The upload is copied to the PRIVATE
    | disk and the run row points at it. Never the public disk: an import file is
    | a spreadsheet of prices, customers and their contact details.
    |
    */
    'disk' => env('IO_IMPORT_DISK', env('PRIVATE_DISK', 'local')),
    'upload_dir' => 'imports',

    /*
    |--------------------------------------------------------------------------
    | Progress granularity
    |--------------------------------------------------------------------------
    |
    | How many rows a queued run processes between writes to import_runs.
    | processed_rows is a progress bar, not an audit figure, so it is worth one
    | UPDATE per N rows rather than one per row.
    |
    */
    'progress_every' => (int) env('IO_PROGRESS_EVERY', 25),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days an uploaded import file is kept on the private disk after its run
    | reaches a terminal state. The run row (counts + errors) is permanent; the
    | file behind it is not.
    |
    */
    'keep_uploads_days' => (int) env('IO_KEEP_UPLOADS_DAYS', 14),

];
