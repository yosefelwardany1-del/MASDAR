<?php

namespace App\Models;

use App\Support\IO\ImportResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Record of a bulk import, and the PROGRESS row of a queued one: a run is created in
 * `queued`, the worker moves it to `running`, and it settles on `completed` or `failed`.
 * A synchronous run (under config('io.queue_threshold')) writes exactly one row in a
 * terminal state.
 *
 * ONE WRITER (CLAUDE.md): import_runs is written only by the methods below — the
 * controller and the worker call them, never create()/update()/save().
 */
class ImportRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'resource', 'filename', 'upload_path', 'is_preview',
        'total_rows', 'processed_rows',
        'created_count', 'updated_count', 'failed_count', 'status', 'errors', 'notices',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'notices' => 'array',
        'is_preview' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ---- the writer ---------------------------------------------------------------

    /** A run handed to a worker: its upload is on the private disk, nothing processed yet. */
    public static function queue(User $user, string $resource, string $filename, string $uploadPath, bool $preview, int $rows): self
    {
        return self::create([
            'user_id' => $user->id,
            'resource' => $resource,
            'filename' => $filename,
            'upload_path' => $uploadPath,
            'is_preview' => $preview,
            'total_rows' => $rows,
            'processed_rows' => 0,
            'status' => self::QUEUED,
        ]);
    }

    /**
     * A commit that finished inside the request. A synchronous PREVIEW writes no row: it
     * changed nothing, and previews in the history would bury the commits that did.
     */
    public static function recordFinished(User $user, string $resource, string $filename, ImportResult $result): self
    {
        return self::create([
            'user_id' => $user->id,
            'resource' => $resource,
            'filename' => $filename,
            'is_preview' => false,
            'total_rows' => $result->totalRows,
            'processed_rows' => $result->totalRows,
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'failed_count' => $result->failed(),
            'status' => self::verdict($result),
            'errors' => $result->errors,
            'notices' => $result->notices,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /** The worker has picked the run up. */
    public function start(): void
    {
        $this->forceFill(['status' => self::RUNNING, 'started_at' => now()])->save();
    }

    /** The row count, re-established from the file itself: what the progress bar is a fraction OF. */
    public function countRows(int $total): void
    {
        $this->forceFill(['total_rows' => $total])->save();
    }

    /**
     * A bare UPDATE, not save(): the model in memory is mid-run and saving it would
     * write back stale counts over anything else. Progress is one column.
     */
    public function recordProgress(int $processed): void
    {
        self::whereKey($this->id)->update(['processed_rows' => $processed, 'updated_at' => now()]);
    }

    public function settle(ImportResult $result): void
    {
        $this->forceFill([
            'total_rows' => $result->totalRows,
            'processed_rows' => $result->totalRows,
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'failed_count' => $result->failed(),
            'status' => self::verdict($result),
            'errors' => $result->errors,
            'notices' => $result->notices,
            'finished_at' => now(),
        ])->save();
    }

    public function settleFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::FAILED,
            'errors' => array_merge($this->errors ?? [], [
                ['row' => 0, 'messages' => [__('The import stopped: :reason', ['reason' => $message])]],
            ]),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * 'failed' means NOTHING landed. A file where 900 of 1000 rows imported is
     * 'completed' with 100 errors, and the errors blob is where the detail lives.
     */
    private static function verdict(ImportResult $result): string
    {
        return ($result->failed() > 0 && ($result->created + $result->updated) === 0)
            ? self::FAILED
            : self::COMPLETED;
    }

    /** Still queued or running — the UI keeps polling while this is true. */
    /** B31: the status in the reader's language (an English key, translated like every other). */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => __('Queued'),
            self::RUNNING => __('Running'),
            self::COMPLETED => __('Completed'),
            self::FAILED => __('Failed'),
            default => (string) $this->status,
        };
    }

    public function inProgress(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Whole percent complete, for the progress bar. 100 once terminal. */
    public function progressPercent(): int
    {
        if (! $this->inProgress()) {
            return 100;
        }

        if ($this->total_rows < 1) {
            return 0;
        }

        return (int) min(99, floor(($this->processed_rows / $this->total_rows) * 100));
    }

    /**
     * The shape the import screen polls for. Deliberately NOT the raw model: `errors`
     * can be thousands of rows, and a poll every two seconds should not carry them
     * until the run is over.
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'in_progress' => $this->inProgress(),
            'is_preview' => $this->is_preview,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'percent' => $this->progressPercent(),
            'created' => $this->created_count,
            'updated' => $this->updated_count,
            'failed' => $this->failed_count,
            'errors' => $this->inProgress() ? [] : ($this->errors ?? []),
            'notices' => $this->inProgress() ? [] : ($this->notices ?? []),
        ];
    }
}
