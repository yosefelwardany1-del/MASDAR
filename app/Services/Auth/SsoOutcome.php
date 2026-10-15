<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * What a Google sign-in resolved to. Exactly three answers, so the controller is a
 * switch and every branch is a test.
 */
final class SsoOutcome
{
    public const SIGNED_IN = 'signed_in';

    public const PENDING = 'pending';

    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $kind,
        public readonly ?User $user = null,
        public readonly ?string $message = null,
        public readonly bool $created = false,
    ) {}

    public static function signedIn(User $user): self
    {
        return new self(self::SIGNED_IN, $user);
    }

    public static function pending(User $user, bool $created): self
    {
        return new self(self::PENDING, $user, null, $created);
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, null, $message);
    }

    public function is(string $kind): bool
    {
        return $this->kind === $kind;
    }
}
