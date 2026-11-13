<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Context;
use AllianceBB\Core\Database;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Request;

/**
 * Cookie sessions stored in the database, so any worker or server can serve any request.
 * CSRF uses a double-submit cookie checked on every POST from a browser.
 */
final class Auth
{
    public const SESSION_COOKIE = 'abb_session';
    public const CSRF_COOKIE = 'abb_csrf';
    public const FLASH_COOKIE = 'abb_flash';
    private const LIFETIME = 60 * 60 * 24 * 30;

    public function __construct(private readonly Database $db)
    {
    }

    public function context(Request $request): Context
    {
        $csrf = $request->cookies[self::CSRF_COOKIE] ?? '';
        $newCsrf = !is_string($csrf) || !preg_match('/^[a-f0-9]{64}$/', $csrf);
        if ($newCsrf) {
            $csrf = bin2hex(random_bytes(32));
        }
        $ctx = new Context($request, $csrf);
        if ($newCsrf) {
            $ctx->setCookies[] = [self::CSRF_COOKIE, $csrf, time() + self::LIFETIME];
        }

        $token = $request->cookies[self::SESSION_COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            $user = $this->db->one(
                'SELECT u.*, s.id AS session_id, s.last_seen_at FROM sessions s JOIN users u ON u.id = s.user_id
                 WHERE s.id = ? AND s.expires_at > now() AND u.banned_at IS NULL',
                [hash('sha256', $token)],
            );
            if ($user !== null) {
                unset($user['password_hash']);
                $ctx->user = $user;
                if (strtotime((string) $user['last_seen_at']) < time() - 300) {
                    $this->db->run('UPDATE sessions SET last_seen_at = now() WHERE id = ?', [$user['session_id']]);
                }
            }
        }

        $flash = $request->cookies[self::FLASH_COOKIE] ?? '';
        if (is_string($flash) && $flash !== '') {
            $decoded = json_decode($flash, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['type'], $item['message'])) {
                        $ctx->flash[] = ['type' => (string) $item['type'], 'message' => (string) $item['message']];
                    }
                }
            }
            $ctx->setCookies[] = [self::FLASH_COOKIE, '', time() - 3600];
        }
        return $ctx;
    }

    public function checkCsrf(Context $ctx): void
    {
        $sent = $ctx->request->input('_csrf') ?: $ctx->request->header('x-csrf-token');
        if (!hash_equals($ctx->csrfToken, $sent)) {
            throw new HttpException(419);
        }
    }

    /** @param array<string, mixed> $user */
    public function login(Context $ctx, array $user): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run(
            'INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, now() + make_interval(secs => ?))',
            [hash('sha256', $token), (int) $user['id'], self::LIFETIME],
        );
        $ctx->setCookies[] = [self::SESSION_COOKIE, $token, time() + self::LIFETIME];
        unset($user['password_hash']);
        $ctx->user = $user;
    }

    public function logout(Context $ctx): void
    {
        $token = $ctx->request->cookies[self::SESSION_COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            $this->db->run('DELETE FROM sessions WHERE id = ?', [hash('sha256', $token)]);
        }
        $ctx->setCookies[] = [self::SESSION_COOKIE, '', time() - 3600];
        $ctx->user = null;
    }

    public function purgeExpired(): void
    {
        $this->db->run('DELETE FROM sessions WHERE expires_at < now()');
    }
}
