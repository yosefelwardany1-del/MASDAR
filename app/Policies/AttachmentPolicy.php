<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Attachment access is derived entirely from the PARENT's screen: download needs
 * `{screen}.view`, delete needs `{screen}.edit`. Fail-closed: an orphaned attachment or a
 * parent that is not attachable denies access.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->parentAllows($user, $attachment, 'view');
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->parentAllows($user, $attachment, 'edit');
    }

    private function parentAllows(User $user, Attachment $attachment, string $level): bool
    {
        $parent = $attachment->attachable;
        if (! $parent || ! method_exists($parent, 'attachmentModule')) {
            return false;
        }

        return $user->can($parent->attachmentModule().'.'.$level);
    }
}
