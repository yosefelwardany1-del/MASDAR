<?php

namespace App\Support\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session handler, without the write nobody needs.
 *
 * Laravel rewrites a request's session row at the end of EVERY request, even when nothing
 * in the session changed, just to move `last_activity` forward. In production the sessions
 * table lives on Neon, a network hop away, and many requests (polls, scans) arrive on an
 * unchanged session — so half the session's database work was a round trip that changed
 * one timestamp.
 *
 * This skips that write when the session the request read is byte-for-byte what it would
 * write, belongs to the same person, and was touched less than TOUCH_SECONDS ago. Anything
 * that changes the session (signing in or out, the company switcher, a flash message, a new
 * CSRF token) is written exactly as before, and an idle session is still touched every five
 * minutes — sessions live a week (SESSION_LIFETIME), so that cannot expire anybody early.
 * Nothing in the ERP reads `last_activity`; only the session's own expiry and garbage
 * collection do.
 */
class LazyDatabaseSessionHandler extends DatabaseSessionHandler
{
    public const TOUCH_SECONDS = 300;

    private ?string $readId = null;

    private ?string $readPayload = null;

    private ?int $readActivity = null;

    private ?string $readUserId = null;

    public function read($sessionId): string|false
    {
        $session = (object) $this->getQuery()->find($sessionId);

        if ($this->expired($session)) {
            $this->exists = true;
            $this->forgetRead();

            return '';
        }

        if (isset($session->payload)) {
            $this->exists = true;
            $this->readId = (string) $sessionId;
            $this->readPayload = (string) $session->payload;
            $this->readActivity = isset($session->last_activity) ? (int) $session->last_activity : null;
            $this->readUserId = isset($session->user_id) ? (string) $session->user_id : null;

            return base64_decode($session->payload);
        }

        $this->forgetRead();

        return '';
    }

    public function write($sessionId, $data): bool
    {
        if ($this->unchanged((string) $sessionId, (string) $data)) {
            return true;
        }

        $written = parent::write($sessionId, $data);
        $this->forgetRead();

        return $written;
    }

    public function destroy($sessionId): bool
    {
        $this->forgetRead();

        return parent::destroy($sessionId);
    }

    private function unchanged(string $sessionId, string $data): bool
    {
        if (! $this->exists || $this->readId !== $sessionId || $this->readPayload === null || $this->readActivity === null) {
            return false;
        }
        if ($this->currentTime() - $this->readActivity >= self::TOUCH_SECONDS) {
            return false;
        }
        if ($this->container) {
            $userId = $this->userId();
            if (($userId === null ? null : (string) $userId) !== $this->readUserId) {
                return false;
            }
        }

        return hash_equals($this->readPayload, base64_encode($data));
    }

    private function forgetRead(): void
    {
        $this->readId = null;
        $this->readPayload = null;
        $this->readActivity = null;
        $this->readUserId = null;
    }
}
