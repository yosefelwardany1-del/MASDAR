<?php

namespace App\Jobs\IO;

use App\Models\ImportRun;
use App\Models\User;
use App\Support\IO\ImportResult;
use App\Support\IO\ImportRunner;
use App\Support\IO\ResourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Runs a bulk import on a worker instead of inside the request.
 *
 * A big file inside a request hits the timeout, and because the processor writes row by
 * row, a timeout would leave HALF THE FILE IMPORTED with nobody told which half.
 *
 * It does not reimplement the import. ImportProcessor keeps its row semantics and its
 * per-row error handling, so one test suite covers both paths; this class supplies the
 * one piece of context a worker lacks and records progress as it goes.
 *
 * THE ACTOR. Writers attribute their rows and their audit entries to auth()->id().
 * A worker starts with nobody authenticated, so the user recorded on the run is
 * re-authenticated for the duration — or the trail of a bulk import reads as though
 * nobody did it.
 *
 * ShouldBeUnique on the RUN id (not the file, not the resource): a double-submitted
 * form creates one run row and dispatches one job for it, and a retry of that job is
 * the same run. Two people importing two different files for the same resource are two
 * runs and must both proceed.
 */
class RunResourceImportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A big import is minutes of validation, not seconds. */
    public int $timeout = 3600;

    /**
     * One attempt. A retry would re-run rows that are ALREADY WRITTEN — the processor
     * upserts, so most rows would be harmless, but afterPersist() side effects are not
     * all idempotent and re-running them silently is worse than reporting a failure a
     * person can look at. `failed()` records what happened either way.
     */
    public int $tries = 1;

    /** Long enough to cover $timeout, so a slow run cannot be dispatched twice. */
    public int $uniqueFor = 3600;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(ResourceRegistry $registry, ImportRunner $runner): void
    {
        $run = ImportRun::find($this->runId);

        if (! $run || ! $run->inProgress()) {
            return;     // pruned, or already finished by an earlier attempt
        }

        $actor = $run->user_id ? User::find($run->user_id) : null;
        if ($actor) {
            Auth::setUser($actor);
        }

        $run->start();

        try {
            $result = $this->execute($run, $registry, $runner);
        } catch (\Throwable $e) {
            report($e);
            $run->settleFailed($e->getMessage());

            throw $e;   // let the queue record it too
        }

        $run->settle($result);
    }

    private function execute(ImportRun $run, ResourceRegistry $registry, ImportRunner $runner): ImportResult
    {
        $schema = $registry->get($run->resource);
        $disk = Storage::disk(config('io.disk'));

        if (! $run->upload_path || ! $disk->exists($run->upload_path)) {
            return new ImportResult(errors: [['row' => 0, 'messages' => [__('The uploaded file is no longer available.')]]]);
        }

        // The parsers want a local path (Spreadsheet reads with PhpSpreadsheet, which
        // does not take a string). Pull the file down to a temp path and always clean
        // up, including when a row throws.
        $temp = tempnam(sys_get_temp_dir(), 'import_');
        file_put_contents($temp, $disk->get($run->upload_path));

        try {
            $extension = strtolower(pathinfo((string) $run->filename, PATHINFO_EXTENSION));

            if ($runner->isJson($schema, $extension)) {
                return $runner->runJson($schema, $temp, ! $run->is_preview);
            }

            $rows = $runner->parse($temp, $extension);

            $run->countRows(count($rows));

            return $runner->runRows(
                $schema,
                $rows,
                ! $run->is_preview,
                fn (int $processed) => $run->recordProgress($processed),
            );
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The queue's own failure hook — a timeout, an out-of-memory kill, or a throw that
     * escaped handle(). Without this a crashed run sits on 'running' forever and the
     * import screen polls it indefinitely.
     */
    public function failed(?\Throwable $e): void
    {
        $run = ImportRun::find($this->runId);

        if ($run && $run->inProgress()) {
            $run->settleFailed($e?->getMessage() ?? __('the worker stopped unexpectedly'));
        }
    }
}
