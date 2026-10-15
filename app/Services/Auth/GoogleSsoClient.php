<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The OAuth conversation with Google for SIGN-IN — nothing else.
 *
 * Plain HTTP, no SDK. It asks for identity scopes only (openid, email, profile) and
 * returns an SsoProfile.
 *
 * Identity comes from Google's userinfo endpoint, called with the access token we just
 * received directly from Google's token endpoint over TLS, authenticated with our client
 * secret. That is what makes the claims trustworthy without verifying an id_token
 * signature by hand: nobody but Google could have issued that token to this client.
 */
class GoogleSsoClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public static function make(): self
    {
        return new self((array) config('sso', []));
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /** Both halves of the client credential are known (env only). */
    public function configured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function clientId(): ?string
    {
        return $this->credential('client_id');
    }

    public function clientSecret(): ?string
    {
        return $this->credential('client_secret');
    }

    private function credential(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** Where the "Continue with Google" button sends the browser. */
    public function authUrl(string $state, string $redirectUri): string
    {
        return $this->config['auth_url'].'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->config['scopes'] ?? 'openid email profile',
            'state' => $state,
            // Identity only, every time: no offline access, no refresh token to store.
            'access_type' => 'online',
            // Always show the account chooser — the person with two Workspace mailboxes
            // must be able to pick, and a shared machine must not auto-select.
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Exchange the callback code for who this is.
     *
     * Returns null on ANY failure and logs why (status codes, never bodies — a token
     * response is a credential).
     */
    public function exchange(string $code, string $redirectUri): ?SsoProfile
    {
        $token = Http::asForm()->timeout(15)->post($this->config['token_url'], [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $token->ok() || ! is_string($token->json('access_token'))) {
            Log::warning('Google sign-in: token exchange failed', [
                'status' => $token->status(),
                'error' => $token->json('error'),
            ]);

            return null;
        }

        $info = Http::withToken($token->json('access_token'))->timeout(15)->get($this->config['userinfo_url']);

        if (! $info->ok()) {
            Log::warning('Google sign-in: userinfo request failed', ['status' => $info->status()]);

            return null;
        }

        $profile = SsoProfile::fromClaims((array) $info->json());
        if (! $profile) {
            Log::warning('Google sign-in: userinfo had no usable subject or e-mail');
        }

        return $profile;
    }
}
