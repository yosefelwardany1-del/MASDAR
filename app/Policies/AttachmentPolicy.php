<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Attachment access is derived entirely from the PARENT: download needs `{screen}.view`,
 * delete needs `{screen}.edit`. A parent with its own rule (attachmentAllows — a party that is
 * several kinds, a client a sales user may not open) decides for itself. Fail-closed: an
 * orphaned attachment or a parent that is not attachable denies access.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->parentAllows($user, $attachment, 'view');
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->parentAllows($user, $attachment, 'delete');
    }

    private function parentAllows(User $user, Attachment $attachment, string $level): bool
    {
        $parent = $attachment->attachable;
        if (! $parent || ! method_exists($parent, 'attachmentModule')) {
            return false;
        }
        if (method_exists($parent, 'attachmentAllows')) {
            return $parent->attachmentAllows($user, $level);
        }

        // Without a rule of its own, deleting a file needs the same level as uploading one.
        return $user->can($parent->attachmentModule().'.'.($level === 'delete' ? 'edit' : $level));
    }
}
