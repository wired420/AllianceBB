<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Database;
use AllianceBB\Core\Hooks;
use InvalidArgumentException;

final class Users
{
    public function __construct(private readonly Database $db, private readonly Hooks $hooks)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        return $this->db->one(
            'SELECT * FROM users WHERE lower(username) = lower(?) OR lower(email) = lower(?)',
            [$login, $login],
        );
    }

    /** @return array<string, mixed> the new user */
    public function register(string $username, string $email, string $password, string $role = 'member'): array
    {
        $username = trim($username);
        $email = trim($email);
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            $errors[] = 'Usernames are 3 to 32 letters, numbers, dots, dashes or underscores.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Passwords need at least 8 characters.';
        }
        if ($errors === [] && $this->db->value('SELECT 1 FROM users WHERE lower(username) = lower(?)', [$username])) {
            $errors[] = 'That username is taken.';
        }
        if ($errors === [] && $this->db->value('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$email])) {
            $errors[] = 'That email address already has an account.';
        }
        $errors = $this->hooks->applyFilters('user.register.errors', $errors, $username, $email);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        $user = $this->db->one(
            'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?) RETURNING *',
            [$username, $email, $hash, $role],
        );
        $this->hooks->doAction('user.registered', $user);
        return $user;
    }

    /** @return array<string, mixed>|null the user when the password matches */
    public function verify(string $login, string $password): ?array
    {
        $user = $this->findByLogin($login);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash((string) $user['password_hash'], $algo)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, $algo), $user['id']]);
        }
        return $user;
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 100, int $offset = 0): array
    {
        return $this->db->all('SELECT * FROM users ORDER BY id LIMIT ? OFFSET ?', [$limit, $offset]);
    }

    public function setRole(int $id, string $role): void
    {
        if (!in_array($role, ['admin', 'moderator', 'member'], true)) {
            throw new InvalidArgumentException('Unknown role');
        }
        $this->db->run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
    }

    public function setBanned(int $id, bool $banned): void
    {
        $this->db->run('UPDATE users SET banned_at = CASE WHEN ? THEN now() ELSE NULL END WHERE id = ?', [$banned, $id]);
        if ($banned) {
            $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$id]);
        }
        $this->hooks->doAction($banned ? 'user.banned' : 'user.unbanned', $id);
    }

    public function updateProfile(int $id, string $displayName, string $avatarUrl): void
    {
        if ($avatarUrl !== '' && !preg_match('#^https://#i', $avatarUrl)) {
            throw new InvalidArgumentException('Avatar links must start with https://');
        }
        $this->db->run(
            'UPDATE users SET display_name = NULLIF(?, \'\'), avatar_url = NULLIF(?, \'\') WHERE id = ?',
            [mb_substr(trim($displayName), 0, 64), $avatarUrl, $id],
        );
    }
}
