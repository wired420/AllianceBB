<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Context;
use AllianceBB\Core\Database;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Request;
use InvalidArgumentException;

/** Personal access tokens that apps send as "Authorization: Bearer <token>". */
final class ApiTokens
{
    public const MAX_PER_USER = 50;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{token: string, row: array<string, mixed>} the token is shown once and never stored */
    public function create(int $userId, string $name, string $scope): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Give the token a name of 1 to 60 characters, such as the app it is for.');
        }
        if (!in_array($scope, ['read', 'write'], true)) {
            throw new InvalidArgumentException('The scope must be "read" or "write".');
        }
        if ((int) $this->db->value('SELECT count(*) FROM api_tokens WHERE user_id = ?', [$userId]) >= self::MAX_PER_USER) {
            throw new InvalidArgumentException('You have too many tokens. Revoke some you no longer use.');
        }
        $token = 'abbt_' . bin2hex(random_bytes(24));
        $row = $this->db->one(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix, scope) VALUES (?, ?, ?, ?, ?)
             RETURNING id, name, prefix, scope, last_used_at, created_at',
            [$userId, $name, hash('sha256', $token), substr($token, 0, 12), $scope],
        );
        return ['token' => $token, 'row' => (array) $row];
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->all(
            'SELECT id, name, prefix, scope, last_used_at, created_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
    }

    public function revoke(int $userId, int $tokenId): bool
    {
        return $this->db->run('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$tokenId, $userId])->rowCount() > 0;
    }

    /**
     * The request context for an API call. Cookies are ignored, so a browser's
     * session can never be used cross-site against the API; only a token counts.
     */
    public function context(Request $request): Context
    {
        $ctx = new Context($request, bin2hex(random_bytes(16)));
        $header = $request->header('authorization');
        if ($header === '') {
            return $ctx;
        }
        if (!preg_match('/^Bearer\s+(abbt_[a-f0-9]{48})$/i', trim($header), $m)) {
            throw new HttpException(401, 'Send your token as "Authorization: Bearer abbt_…".');
        }
        $row = $this->db->one(
            'SELECT u.*, t.id AS token_id, t.scope AS token_scope, t.last_used_at AS token_last_used_at
               FROM api_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND u.banned_at IS NULL',
            [hash('sha256', $m[1])],
        ) ?? throw new HttpException(401, 'That token is not valid. It may have been revoked.');

        $ctx->apiToken = ['id' => (int) $row['token_id'], 'scope' => (string) $row['token_scope']];
        $last = $row['token_last_used_at'];
        if ($last === null || strtotime((string) $last) < time() - 300) {
            $this->db->run('UPDATE api_tokens SET last_used_at = now() WHERE id = ?', [$row['token_id']]);
        }
        unset($row['password_hash'], $row['token_id'], $row['token_scope'], $row['token_last_used_at']);
        $ctx->user = $row;
        return $ctx;
    }
}
