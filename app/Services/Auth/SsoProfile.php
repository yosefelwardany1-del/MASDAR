<?php

namespace App\Services\Auth;

use App\Models\UserSsoIdentity;

/**
 * What Google told us about the person who just signed in. Immutable, already
 * normalised, and the only shape the sign-in rules ever look at — so a test can
 * construct one without a network and the rules cannot depend on a raw JSON key.
 */
final class SsoProfile
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name = null,
        public readonly ?string $hostedDomain = null,
        public readonly ?string $picture = null,
    ) {}

    /**
     * From Google's OpenID userinfo document.
     *
     * @param  array<string,mixed>  $claims
     */
    public static function fromClaims(array $claims): ?self
    {
        $sub = trim((string) ($claims['sub'] ?? ''));
        $email = UserSsoIdentity::normalise($claims['email'] ?? null);

        if ($sub === '' || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        // Google sends a real boolean here; the older v2 endpoint sent verified_email.
        $verified = $claims['email_verified'] ?? $claims['verified_email'] ?? false;

        return new self(
            sub: $sub,
            email: $email,
            emailVerified: filter_var($verified, FILTER_VALIDATE_BOOLEAN),
            name: filled($claims['name'] ?? null) ? trim((string) $claims['name']) : null,
            hostedDomain: filled($claims['hd'] ?? null) ? strtolower(trim((string) $claims['hd'])) : null,
            picture: filled($claims['picture'] ?? null) ? (string) $claims['picture'] : null,
        );
    }

    public function domain(): string
    {
        return UserSsoIdentity::domainOf($this->email);
    }

    /** A display name to create an account with when Google sent none. */
    public function displayName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        // fady.essam → Fady Essam
        $local = strstr($this->email, '@', true) ?: $this->email;

        return ucwords(str_replace(['.', '_', '-'], ' ', $local));
    }
}
