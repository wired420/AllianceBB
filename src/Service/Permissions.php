<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Context;
use AllianceBB\Core\Hooks;

/**
 * Every permission check goes through the "permission" filter, so a plugin
 * can grant or deny any ability for any user.
 */
final class Permissions
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    /** @param array<string, mixed>|null $subject a node, topic or post row */
    public function can(Context $ctx, string $ability, ?array $subject = null): bool
    {
        $allowed = match ($ability) {
            'admin.access' => $ctx->isAdmin(),
            'topic.create' => $ctx->isLoggedIn() && ($subject['type'] ?? '') === 'forum',
            'post.reply' => $ctx->isLoggedIn() && ($ctx->isModerator() || !($subject['locked'] ?? false)),
            'post.edit', 'post.delete' => $ctx->isLoggedIn() && $subject !== null
                && ($ctx->isModerator() || (int) ($subject['author_user_id'] ?? 0) === $ctx->userId()),
            'topic.moderate' => $ctx->isModerator(),
            default => false,
        };
        return (bool) $this->hooks->applyFilters('permission', $allowed, $ability, $subject, $ctx);
    }
}
