<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One uploaded document attached to any model using HasAttachments. Files live on a
 * private disk and are served only through AttachmentController. Written only by
 * AttachmentService.
 */
class Attachment extends Model
{
    protected $fillable = [
        'attachable_type', 'attachable_id', 'uploaded_by',
        'file_name', 'disk', 'path', 'size', 'mime', 'title',
    ];

    protected $casts = ['size' => 'integer'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** Human-readable size, e.g. "2.4 MB". */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }

    public function iconClass(): string
    {
        if ($this->isImage()) {
            return 'bi-file-earmark-image';
        }

        return match (strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION))) {
            'pdf' => 'bi-file-earmark-pdf',
            'doc', 'docx' => 'bi-file-earmark-word',
            'xls', 'xlsx' => 'bi-file-earmark-excel',
            default => 'bi-file-earmark',
        };
    }
}
