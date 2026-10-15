<?php

namespace App\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One place that knows how a picture belonging to a record is stored, replaced, removed
 * and served — profile photos and the company logo today.
 *
 * The rules are the reason this is a service rather than copies of the same five lines:
 *
 *  - PRIVATE disk, never `public`. On Render the local disk is a container filesystem
 *    wiped on every restart, so anything written there is temporary unless PRIVATE_DISK
 *    points at S3; and a public URL is guessable and permanent, which turns "our team can
 *    see each other" into "anyone with the link can". Every image is streamed through a
 *    route that authorises first.
 *  - The disk NAME is stored beside the path (`{column}_disk`), so a row written while the
 *    app pointed at the local disk keeps resolving after PRIVATE_DISK is switched to S3.
 *  - Storing REPLACES: one picture per record. No orphan files.
 *
 * The service knows nothing about who is asking. Callers authorise before calling, which
 * keeps each access rule beside the audience it governs.
 */
class ImageStore
{
    public const MAX_KB = 4096;

    /**
     * Validation for an uploaded image field.
     *
     * An explicit mime list rather than Laravel's generic `image` rule: that rule accepts
     * SVG, which is a document that can carry script.
     *
     * @return array<int,string>
     */
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB];
    }

    /**
     * @param  string  $folder  storage prefix, e.g. "company-logos" — the record id is appended
     */
    public function store(Model $model, UploadedFile $file, string $folder, string $column): void
    {
        $disk = config('attachments.disk', 'local');

        $this->delete($model, $column);

        $path = $file->store($folder.'/'.$model->getKey(), $disk);

        $model->forceFill([$column => $path, $column.'_disk' => $disk])->save();
    }

    public function delete(Model $model, string $column): void
    {
        if ($model->{$column}) {
            // A missing file is not an error worth failing an upload over — the row is
            // the thing that must end up correct.
            rescue(fn () => Storage::disk($this->disk($model, $column))->delete($model->{$column}), report: false);
        }

        $model->forceFill([$column => null, $column.'_disk' => null])->save();
    }

    public function has(Model $model, string $column): bool
    {
        return filled($model->{$column});
    }

    public function stream(Model $model, string $column): StreamedResponse
    {
        abort_unless($this->has($model, $column), 404);

        $disk = Storage::disk($this->disk($model, $column));

        abort_unless($disk->exists($model->{$column}), 404);

        return $disk->response(
            $model->{$column},
            null,
            // Private, but cacheable BY THE BROWSER: a list page renders a dozen of these
            // and a headshot or a logo does not change hourly.
            ['Cache-Control' => 'private, max-age=3600'],
        );
    }

    /** The picture's bytes, or null when there is none — for a caller that re-renders it. */
    public function bytes(Model $model, string $column): ?string
    {
        if (! $this->has($model, $column)) {
            return null;
        }
        $disk = Storage::disk($this->disk($model, $column));

        return $disk->exists($model->{$column}) ? $disk->get($model->{$column}) : null;
    }

    private function disk(Model $model, string $column): string
    {
        return $model->{$column.'_disk'} ?: config('attachments.disk', 'local');
    }
}
