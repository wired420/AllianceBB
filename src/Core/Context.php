<?php

declare(strict_types=1);

namespace AllianceBB\Core;

use AllianceBB\Http\Request;

/** Everything that belongs to one request. Never stored on long-lived services (worker mode). */
final class Context
{
    /** @var array<string, mixed>|null */
    public ?array $user = null;

    /** @var list<array{type: string, message: string}> */
    public array $flash = [];

    /** @var list<array{type: string, message: string}> flash messages to show on the next page */
    public array $nextFlash = [];

    /** @var list<array{0: string, 1: string, 2: int}> cookies to set: name, value, expires */
    public array $setCookies = [];

    public function __construct(
        public readonly Request $request,
        public string $csrfToken,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    public function userId(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    public function role(): string
    {
        return $this->user['role'] ?? 'guest';
    }

    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    public function isModerator(): bool
    {
        return in_array($this->role(), ['admin', 'moderator'], true);
    }

    public function flash(string $message, string $type = 'success'): void
    {
        $this->nextFlash[] = ['type' => $type, 'message' => $message];
    }
}
