<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Support\Audit\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one writer of attachments. Files get a framework-generated random name (no
 * client-controlled path, no traversal) on a private disk, under a per-parent folder.
 * Every upload and removal is audited in the same transaction.
 */
class AttachmentService
{
    public function store(Model $attachable, UploadedFile $file, ?int $userId = null, ?string $title = null): Attachment
    {
        $disk = (string) config('attachments.disk', 'local');
        $path = $file->store($this->folder($attachable), $disk);

        try {
            return DB::transaction(function () use ($attachable, $file, $userId, $title, $disk, $path) {
                $attachment = $attachable->attachments()->create([
                    'uploaded_by' => $userId,
                    'file_name' => $this->safeName($file->getClientOriginalName()),
                    'disk' => $disk,
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                    'title' => $title ? mb_substr($title, 0, 255) : null,
                ]);
                AuditTrail::record('created', $attachment, [], $attachment->only(['attachable_type', 'attachable_id', 'file_name', 'size', 'mime']));

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // no orphan file when the row could not be written

            throw $e;
        }
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment) {
            AuditTrail::record('deleted', $attachment, $attachment->only(['attachable_type', 'attachable_id', 'file_name', 'size', 'mime']));
            $attachment->delete();
        });

        Storage::disk($attachment->disk)->delete($attachment->path);
    }

    /** attachments/TestDoc/42 — each parent's files together. */
    private function folder(Model $attachable): string
    {
        return 'attachments/'.class_basename($attachable).'/'.$attachable->getKey();
    }

    /** Strip any directory components and cap the length of the display name. */
    private function safeName(string $name): string
    {
        return mb_substr(basename(str_replace('\\', '/', $name)), 0, 255);
    }
}
