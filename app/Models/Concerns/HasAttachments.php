<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Document attachments for any model. A using class gains attachments() and declares the
 * screen that gates them (attachmentModule): `{screen}.view` to download, `{screen}.edit`
 * to upload or delete. Storage and authorisation live in AttachmentService and
 * AttachmentPolicy. No model events: parents in this system are never hard-deleted.
 */
trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /** The screen key (config/screens.php) whose permissions gate this model's attachments. */
    abstract public function attachmentModule(): string;
}
