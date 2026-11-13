<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

use AllianceBB\Core\Context;
use AllianceBB\Core\Database;
use AllianceBB\Core\Hooks;
use AllianceBB\Core\Uuid;
use AllianceBB\Http\Request;
use AllianceBB\Live\Broadcaster;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Posts;
use AllianceBB\Service\Settings;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sharing sections between AllianceBB sites.
 *
 * The site that owns a section is its hub. Every post in a shared section,
 * written on any site, goes to the owner, which records it in the section's
 * event log and sends a webhook to every subscribing site. Subscribers then
 * fetch the new events with their key. Authors from other sites show as
 * user@Handle. See the design spec for the full protocol.
 */
final class Federation
{
    public const API = '/api/alliance/v1';
    public const PROTOCOL_VERSION = 1;
    private const SNAPSHOT_TOPICS = 50;
    private const SNAPSHOT_POSTS = 200;

    /** Set while applying changes that came from another site, so they are not sent back. */
    private bool $importing = false;

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly Hooks $hooks,
        private readonly Nodes $nodes,
        private readonly Posts $posts,
        private readonly Broadcaster $live,
        private readonly Jobs $jobs,
        private readonly HttpClient $http,
    ) {
    }

    // =====================================================================
    // This site
    // =====================================================================

    public function mode(): string
    {
        $mode = (string) $this->settings->get('federation.mode');
        return in_array($mode, ['public', 'private', 'off'], true) ? $mode : 'private';
    }

    public function enabled(): bool
    {
        return $this->mode() !== 'off';
    }

    public function selfUrl(): string
    {
        return rtrim((string) $this->settings->get('site.url'), '/');
    }

    public function selfName(): string
    {
        return (string) $this->settings->get('site.name');
    }

    public function webhookUrl(): string
    {
        return $this->selfUrl() . self::API . '/webhook';
    }

    /** "Gaming Hub!" becomes "GamingHub". */
    public static function baseHandle(string $name): string
    {
        $handle = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '', $name);
        return $handle === '' ? 'site' : mb_substr($handle, 0, 40);
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new InvalidArgumentException('Site addresses look like https://forum.example.com');
        }
        return rtrim($url, '/');
    }

    // =====================================================================
    // Sites, authors and connections
    // =====================================================================

    /**
     * Find or record another site. The first site to use a name keeps it as its
     * handle; later sites with the same name become Name2, Name3 and so on.
     * @return array<string, mixed>
     */
    public function remoteSite(string $baseUrl, string $name): array
    {
        $baseUrl = self::normalizeUrl($baseUrl);
        $existing = $this->db->one('SELECT * FROM remote_sites WHERE base_url = ?', [$baseUrl]);
        if ($existing !== null) {
            if ($name !== '' && $existing['name'] !== $name) {
                $this->db->run('UPDATE remote_sites SET name = ? WHERE id = ?', [$name, $existing['id']]);
                $existing['name'] = $name;
            }
            return $existing;
        }
        $base = self::baseHandle($name);
        $ownHandle = strtolower(self::baseHandle($this->selfName()));
        for ($n = 1; ; $n++) {
            $handle = $n === 1 ? $base : $base . $n;
            $taken = strtolower($handle) === $ownHandle
                || $this->db->value('SELECT 1 FROM remote_sites WHERE lower(handle) = lower(?)', [$handle]);
            if (!$taken) {
                break;
            }
        }
        return $this->db->one(
            'INSERT INTO remote_sites (base_url, name, handle) VALUES (?, ?, ?)
             ON CONFLICT (base_url) DO UPDATE SET name = EXCLUDED.name RETURNING *',
            [$baseUrl, mb_substr($name !== '' ? $name : $base, 0, 60), $handle],
        );
    }

    /**
     * Who wrote something, as another site describes them. Authors from this
     * site map back to local accounts.
     * @param array<string, mixed> $author username, display_name, avatar_url, site_url, site_name
     * @return array{user_id: ?int, remote_id: ?int}
     */
    public function resolveAuthor(array $author): array
    {
        $username = mb_substr((string) ($author['username'] ?? ''), 0, 64);
        $siteUrl = rtrim((string) ($author['site_url'] ?? ''), '/');
        if ($username === '' || $siteUrl === '') {
            throw new InvalidArgumentException('Author is missing.');
        }
        if ($siteUrl === $this->selfUrl()) {
            $id = $this->db->value('SELECT id FROM users WHERE lower(username) = lower(?)', [$username]);
            return ['user_id' => $id === null ? null : (int) $id, 'remote_id' => null];
        }
        $site = $this->remoteSite($siteUrl, (string) ($author['site_name'] ?? ''));
        $avatar = (string) ($author['avatar_url'] ?? '');
        $avatar = preg_match('#^https://[^\s"\'<>]+$#i', $avatar) ? $avatar : null;
        $displayName = trim((string) ($author['display_name'] ?? ''));
        $row = $this->db->one(
            'INSERT INTO remote_users (remote_site_id, username, display_name, avatar_url) VALUES (?, ?, NULLIF(?, \'\'), ?)
             ON CONFLICT (remote_site_id, username) DO UPDATE
                SET display_name = EXCLUDED.display_name, avatar_url = EXCLUDED.avatar_url
             RETURNING id',
            [$site['id'], $username, mb_substr($displayName, 0, 64), $avatar],
        );
        return ['user_id' => null, 'remote_id' => (int) $row['id']];
    }

    /** @return array<string, mixed> how another site should describe a post's author */
    private function authorPayload(?int $userId, ?int $remoteId): array
    {
        if ($remoteId !== null) {
            $row = $this->db->one(
                'SELECT ru.username, ru.display_name, ru.avatar_url, rs.base_url, rs.name
                   FROM remote_users ru JOIN remote_sites rs ON rs.id = ru.remote_site_id WHERE ru.id = ?',
                [$remoteId],
            );
            if ($row !== null) {
                return [
                    'username' => $row['username'], 'display_name' => $row['display_name'], 'avatar_url' => $row['avatar_url'],
                    'site_url' => $row['base_url'], 'site_name' => $row['name'],
                ];
            }
        }
        $user = $userId !== null ? $this->db->one('SELECT username, display_name, avatar_url FROM users WHERE id = ?', [$userId]) : null;
        return [
            'username' => $user['username'] ?? 'deleted',
            'display_name' => $user['display_name'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'site_url' => $this->selfUrl(),
            'site_name' => $this->selfName(),
        ];
    }

    /**
     * Give another site a key so it can clone our shareable sections.
     * @return array<string, mixed> the connection, including its secret
     */
    public function issueConnection(string $name, string $baseUrl, string $webhookUrl = '', string $via = 'admin'): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Site names are 1 to 60 characters.');
        }
        $site = $this->remoteSite($baseUrl, $name);
        $webhookUrl = trim($webhookUrl);
        if ($webhookUrl !== '' && !preg_match('#^https?://#i', $webhookUrl)) {
            throw new InvalidArgumentException('The webhook address must start with http:// or https://');
        }
        return $this->db->one(
            "INSERT INTO connections (remote_site_id, role, public_id, secret, webhook_url, created_via)
             VALUES (?, 'subscriber', ?, ?, NULLIF(?, ''), ?) RETURNING *",
            [$site['id'], Signer::newPublicId(), Signer::newSecret(), $webhookUrl, $via],
        );
    }

    /** @return list<array<string, mixed>> */
    public function connections(?string $role = null): array
    {
        return $this->db->all(
            'SELECT c.*, rs.name AS site_name, rs.base_url, rs.handle,
                    (SELECT count(*) FROM subscriptions s WHERE s.connection_id = c.id) AS subscription_count
               FROM connections c JOIN remote_sites rs ON rs.id = c.remote_site_id
              WHERE (?::text IS NULL OR c.role = ?) ORDER BY c.id',
            [$role, $role],
        );
    }

    /** @return array<string, mixed>|null */
    public function connection(int $id): ?array
    {
        return $this->db->one(
            'SELECT c.*, rs.name AS site_name, rs.base_url, rs.handle FROM connections c
               JOIN remote_sites rs ON rs.id = c.remote_site_id WHERE c.id = ?',
            [$id],
        );
    }

    public function revoke(int $connectionId): void
    {
        $this->db->run("UPDATE connections SET status = 'revoked' WHERE id = ?", [$connectionId]);
        $this->db->run('DELETE FROM subscriptions WHERE connection_id = ?', [$connectionId]);
    }

    public function setWebhook(int $connectionId, string $url): void
    {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('The webhook address must start with http:// or https://');
        }
        $this->db->run("UPDATE connections SET webhook_url = NULLIF(?, '') WHERE id = ?", [trim($url), $connectionId]);
    }

    /** The code the owner's admin copies and the subscriber's admin pastes. */
    public function connectionCode(int $connectionId, int $nodeId): string
    {
        $conn = $this->connection($connectionId);
        $node = $this->nodes->find($nodeId);
        if ($conn === null || $conn['role'] !== 'subscriber' || $conn['status'] !== 'active') {
            throw new InvalidArgumentException('That connection is not active.');
        }
        if ($node === null || !$node['shareable'] || $node['origin_connection_id'] !== null) {
            throw new InvalidArgumentException('Mark the section as shareable first.');
        }
        $data = [
            'v' => self::PROTOCOL_VERSION, 'url' => $this->selfUrl(), 'name' => $this->selfName(),
            'node' => $node['guid'], 'title' => $node['title'], 'key' => $conn['public_id'], 'secret' => $conn['secret'],
        ];
        return 'abb1.' . rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    public static function parseCode(string $code): array
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (!str_starts_with($code, 'abb1.')) {
            throw new InvalidArgumentException('That is not an AllianceBB connection code. Codes start with abb1.');
        }
        $data = json_decode((string) base64_decode(strtr(substr($code, 5), '-_', '+/')), true);
        foreach (['url', 'name', 'node', 'key', 'secret'] as $field) {
            if (!is_array($data) || !is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new InvalidArgumentException('That connection code is damaged. Copy it again.');
            }
        }
        if (!Uuid::isValid($data['node'])) {
            throw new InvalidArgumentException('That connection code is damaged. Copy it again.');
        }
        return $data;
    }

    // =====================================================================
    // Signed requests
    // =====================================================================

    /**
     * Call another site over a connection.
     * @param array<string, mixed>|null $data
     * @return array{status: int, json: array<string, mixed>}
     */
    public function call(array $conn, string $method, string $path, ?array $data = null): array
    {
        $url = rtrim((string) $conn['base_url'], '/') . $path;
        $body = $data === null ? '' : (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts = parse_url($url);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $res = $this->http->request($method, $url, $body, Signer::headers($conn['public_id'], $conn['secret'], $method, $target, $body));
        if (in_array($res['status'], [401, 403], true) && ($res['json']['error'] ?? '') === 'connection_revoked') {
            $this->db->run("UPDATE connections SET status = 'revoked_by_remote' WHERE id = ?", [$conn['id']]);
        }
        return ['status' => $res['status'], 'json' => $res['json'] ?? []];
    }

    /**
     * Check a signed request from another site.
     * @return array<string, mixed> the connection it was signed with
     */
    public function verify(Request $request, string $role): array
    {
        $publicId = $request->header('x-alliance-key');
        $conn = $publicId === '' ? null : $this->db->one(
            'SELECT c.*, rs.name AS site_name, rs.base_url, rs.handle FROM connections c
               JOIN remote_sites rs ON rs.id = c.remote_site_id WHERE c.public_id = ?',
            [$publicId],
        );
        $ok = $conn !== null && Signer::verify(
            (string) $conn['secret'],
            $request->method,
            $request->target,
            $request->header('x-alliance-timestamp'),
            $request->header('x-alliance-nonce'),
            $request->body,
            $request->header('x-alliance-signature'),
        );
        if (!$ok || $conn['role'] !== $role) {
            throw new ApiError(401, 'bad_signature', 'The request signature is not valid.');
        }
        if ($conn['status'] !== 'active') {
            throw new ApiError(403, 'connection_revoked', 'This connection has been revoked.');
        }
        try {
            $this->db->run('INSERT INTO fed_nonces (connection_id, nonce) VALUES (?, ?)', [$conn['id'], $request->header('x-alliance-nonce')]);
        } catch (\PDOException) {
            throw new ApiError(401, 'replayed', 'This request was already received.');
        }
        $this->db->run('UPDATE connections SET last_seen_at = now() WHERE id = ?', [$conn['id']]);
        return $conn;
    }

    // =====================================================================
    // Owner side: the event log and fan-out
    // =====================================================================

    /** @return list<array<string, mixed>> shared sections (with subscribers) that contain this node */
    public function sharedRootsFor(int $nodeId): array
    {
        return $this->db->all(
            'WITH RECURSIVE up AS (
                SELECT id, parent_id FROM nodes WHERE id = ?
                UNION ALL SELECT n.id, n.parent_id FROM nodes n JOIN up ON n.id = up.parent_id
             )
             SELECT n.* FROM nodes n JOIN up ON up.id = n.id
              WHERE n.shareable AND n.origin_connection_id IS NULL
                AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.node_id = n.id)',
            [$nodeId],
        );
    }

    /** Record a change in every shared section containing the node and notify subscribers. */
    private function emit(int $nodeId, string $type, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }
        foreach ($this->sharedRootsFor($nodeId) as $root) {
            $this->emitToRoot($root, $type, $payload);
        }
    }

    /** @param array<string, mixed> $root */
    private function emitToRoot(array $root, string $type, array $payload): void
    {
        $seq = (int) $this->db->value(
            'INSERT INTO fed_events (root_node_id, type, payload) VALUES (?, ?, ?::jsonb) RETURNING seq',
            [$root['id'], $type, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
        $subscribers = $this->db->all(
            "SELECT c.id FROM subscriptions s JOIN connections c ON c.id = s.connection_id
              WHERE s.node_id = ? AND c.status = 'active' AND c.webhook_url IS NOT NULL",
            [$root['id']],
        );
        foreach ($subscribers as $sub) {
            $this->jobs->push('webhook', [
                'connection_id' => (int) $sub['id'], 'root_guid' => $root['guid'], 'latest_seq' => $seq,
            ], 'webhook:' . $sub['id'] . ':' . $root['id']);
        }
    }

    /** @return list<array<string, mixed>> the section and everything under it, parents first */
    public function structure(array $root): array
    {
        $rows = $this->db->all(
            'WITH RECURSIVE down AS (
                SELECT n.*, 0 AS depth FROM nodes n WHERE n.id = ?
                UNION ALL SELECT n.*, down.depth + 1 FROM nodes n JOIN down ON n.parent_id = down.id
             )
             SELECT d.guid, p.guid AS parent_guid, d.type, d.title, d.description, d.position, d.depth
               FROM down d LEFT JOIN nodes p ON p.id = d.parent_id ORDER BY d.depth, d.position, d.id',
            [$root['id']],
        );
        foreach ($rows as &$row) {
            if ((int) $row['depth'] === 0) {
                $row['parent_guid'] = null;
            }
            unset($row['depth']);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function postPayload(int $postId): ?array
    {
        $post = $this->db->one(
            'SELECT p.*, t.guid AS topic_guid FROM posts p JOIN topics t ON t.id = p.topic_id WHERE p.id = ?',
            [$postId],
        );
        if ($post === null) {
            return null;
        }
        return [
            'guid' => $post['guid'],
            'topic_guid' => $post['topic_guid'],
            'body' => $post['body'],
            'created_at' => $post['created_at'],
            'edited_at' => $post['edited_at'],
            'author' => $this->authorPayload(
                $post['author_user_id'] === null ? null : (int) $post['author_user_id'],
                $post['author_remote_id'] === null ? null : (int) $post['author_remote_id'],
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function topicPayload(int $topicId): ?array
    {
        $topic = $this->db->one(
            'SELECT t.*, n.guid AS node_guid FROM topics t JOIN nodes n ON n.id = t.node_id WHERE t.id = ?',
            [$topicId],
        );
        if ($topic === null) {
            return null;
        }
        return [
            'guid' => $topic['guid'],
            'node_guid' => $topic['node_guid'],
            'title' => $topic['title'],
            'locked' => (bool) $topic['locked'],
            'pinned' => (bool) $topic['pinned'],
            'created_at' => $topic['created_at'],
            'author' => $this->authorPayload(
                $topic['author_user_id'] === null ? null : (int) $topic['author_user_id'],
                $topic['author_remote_id'] === null ? null : (int) $topic['author_remote_id'],
            ),
        ];
    }

    /** @return array<string, mixed> a topic with its posts */
    private function topicWithPosts(int $topicId): array
    {
        $posts = $this->db->all(
            'SELECT id FROM posts WHERE topic_id = ? AND deleted_at IS NULL ORDER BY created_at, id LIMIT ?',
            [$topicId, self::SNAPSHOT_POSTS],
        );
        return [
            'topic' => $this->topicPayload($topicId),
            'posts' => array_values(array_filter(array_map(fn ($p) => $this->postPayload((int) $p['id']), $posts))),
        ];
    }

    /** @return list<array<string, mixed>> recent topics in a section, for a new subscriber */
    private function snapshot(array $root): array
    {
        $topics = $this->db->all(
            'WITH RECURSIVE down AS (
                SELECT id FROM nodes WHERE id = ? UNION ALL SELECT n.id FROM nodes n JOIN down ON n.parent_id = down.id
             )
             SELECT t.id FROM topics t WHERE t.node_id IN (SELECT id FROM down) AND t.deleted_at IS NULL
              ORDER BY t.last_post_at DESC LIMIT ?',
            [$root['id'], self::SNAPSHOT_TOPICS],
        );
        return array_map(fn ($t) => $this->topicWithPosts((int) $t['id']), array_reverse($topics));
    }

    /** @return array<string, mixed> a shared root that this connection subscribes to */
    public function subscribedRoot(array $conn, string $rootGuid): array
    {
        $root = $this->db->one(
            'SELECT n.* FROM nodes n JOIN subscriptions s ON s.node_id = n.id
              WHERE n.guid = ?::uuid AND s.connection_id = ? AND n.shareable AND n.origin_connection_id IS NULL',
            [Uuid::isValid($rootGuid) ? $rootGuid : '00000000-0000-0000-0000-000000000000', $conn['id']],
        );
        if ($root === null) {
            throw new ApiError(404, 'not_subscribed', 'This site does not subscribe to that section.');
        }
        return $root;
    }

    /** @return array<string, mixed> a node inside the shared section */
    private function nodeInRoot(array $root, string $nodeGuid): array
    {
        $node = Uuid::isValid($nodeGuid) ? $this->db->one(
            'WITH RECURSIVE down AS (
                SELECT id FROM nodes WHERE id = ? UNION ALL SELECT n.id FROM nodes n JOIN down ON n.parent_id = down.id
             ) SELECT * FROM nodes WHERE guid = ?::uuid AND id IN (SELECT id FROM down)',
            [$root['id'], $nodeGuid],
        ) : null;
        if ($node === null) {
            throw new ApiError(404, 'unknown_node', 'That forum is not part of the shared section.');
        }
        return $node;
    }

    /** @return array<string, mixed> a topic inside the shared section */
    private function topicInRoot(array $root, string $topicGuid): array
    {
        $topic = Uuid::isValid($topicGuid) ? $this->db->one(
            'WITH RECURSIVE down AS (
                SELECT id FROM nodes WHERE id = ? UNION ALL SELECT n.id FROM nodes n JOIN down ON n.parent_id = down.id
             ) SELECT * FROM topics WHERE guid = ?::uuid AND node_id IN (SELECT id FROM down) AND deleted_at IS NULL',
            [$root['id'], $topicGuid],
        ) : null;
        if ($topic === null) {
            // The topic may still be on its way; the sender retries.
            throw new ApiError(409, 'unknown_topic', 'That topic is not known here yet.');
        }
        return $topic;
    }

    // ----- owner API handlers ---------------------------------------------

    /** @return array<string, mixed> */
    public function info(): array
    {
        $info = [
            'software' => 'AllianceBB',
            'version' => \AllianceBB\App::VERSION,
            'protocol' => self::PROTOCOL_VERSION,
            'name' => $this->selfName(),
            'url' => $this->selfUrl(),
            'mode' => $this->mode(),
        ];
        if ($this->mode() === 'public') {
            $info['sections'] = $this->db->all(
                "SELECT guid, type, title, description FROM nodes
                  WHERE shareable AND origin_connection_id IS NULL ORDER BY position, id",
            );
        }
        return $info;
    }

    /**
     * Public mode: another site creates its own key.
     * @param array<string, mixed> $data name, base_url, webhook_url
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        if ($this->mode() !== 'public') {
            throw new ApiError(403, 'not_public', 'This site only shares with sites its admin invites.');
        }
        $baseUrl = self::normalizeUrl((string) ($data['base_url'] ?? ''));
        $webhook = (string) ($data['webhook_url'] ?? '');
        $this->http->assertAllowedUrl($baseUrl);
        if ($webhook !== '') {
            $this->http->assertAllowedUrl($webhook);
        }
        $conn = $this->issueConnection((string) ($data['name'] ?? ''), $baseUrl, $webhook, 'public');
        return ['key' => $conn['public_id'], 'secret' => $conn['secret'], 'name' => $this->selfName(), 'url' => $this->selfUrl()];
    }

    /**
     * A subscriber asks to receive a section.
     * @param array<string, mixed> $data node_guid, webhook_url
     * @return array<string, mixed> the section's structure, recent topics and the event cursor
     */
    public function subscribe(array $conn, array $data): array
    {
        $guid = (string) ($data['node_guid'] ?? '');
        $root = Uuid::isValid($guid)
            ? $this->db->one('SELECT * FROM nodes WHERE guid = ?::uuid AND shareable AND origin_connection_id IS NULL', [$guid])
            : null;
        if ($root === null) {
            throw new ApiError(404, 'not_shareable', 'That section is not shared.');
        }
        $webhook = trim((string) ($data['webhook_url'] ?? ''));
        if ($webhook !== '') {
            $this->http->assertAllowedUrl($webhook);
            $this->setWebhook((int) $conn['id'], $webhook);
        }
        $this->db->run(
            'INSERT INTO subscriptions (connection_id, node_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$conn['id'], $root['id']],
        );
        $cursor = (int) $this->db->value('SELECT COALESCE(MAX(seq), 0) FROM fed_events WHERE root_node_id = ?', [$root['id']]);
        return [
            'site' => ['name' => $this->selfName(), 'url' => $this->selfUrl()],
            'root_guid' => $root['guid'],
            'structure' => $this->structure($root),
            'topics' => $this->snapshot($root),
            'cursor' => $cursor,
        ];
    }

    public function unsubscribe(array $conn, string $rootGuid): void
    {
        $root = $this->subscribedRoot($conn, $rootGuid);
        $this->db->run('DELETE FROM subscriptions WHERE connection_id = ? AND node_id = ?', [$conn['id'], $root['id']]);
    }

    /** @return array<string, mixed> */
    public function eventsSince(array $conn, string $rootGuid, int $after, int $limit = 200): array
    {
        $root = $this->subscribedRoot($conn, $rootGuid);
        $events = $this->db->all(
            'SELECT seq, type, payload FROM fed_events WHERE root_node_id = ? AND seq > ? ORDER BY seq LIMIT ?',
            [$root['id'], $after, min(500, max(1, $limit))],
        );
        foreach ($events as &$event) {
            $event['seq'] = (int) $event['seq'];
            $event['payload'] = json_decode((string) $event['payload'], true);
        }
        $latest = (int) $this->db->value('SELECT COALESCE(MAX(seq), 0) FROM fed_events WHERE root_node_id = ?', [$root['id']]);
        return ['events' => $events, 'latest' => $latest];
    }

    /**
     * A subscriber sends a new topic written by one of its users.
     * The author is always taken to be from the sending site.
     * @return array<string, mixed>
     */
    public function acceptTopic(array $conn, string $rootGuid, array $data): array
    {
        $root = $this->subscribedRoot($conn, $rootGuid);
        $topicData = (array) ($data['topic'] ?? []);
        $postData = (array) ($data['post'] ?? []);
        $node = $this->nodeInRoot($root, (string) ($topicData['node_guid'] ?? ''));
        if ($node['type'] !== 'forum') {
            throw new ApiError(422, 'not_a_forum', 'Topics can only be started in a forum.');
        }
        $title = trim((string) ($topicData['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150 || !Uuid::isValid((string) ($topicData['guid'] ?? '')) || !Uuid::isValid((string) ($postData['guid'] ?? ''))) {
            throw new ApiError(422, 'invalid', 'The topic is missing a title or IDs.');
        }
        $author = $this->resolveAuthor(['site_url' => $conn['base_url'], 'site_name' => $conn['site_name']] + (array) ($postData['author'] ?? []));
        $this->importTopicWithPosts($node, $topicData, [$postData], $author);
        return ['status' => 'accepted'];
    }

    /** @return array<string, mixed> */
    public function acceptPost(array $conn, string $rootGuid, array $data): array
    {
        $root = $this->subscribedRoot($conn, $rootGuid);
        $postData = (array) ($data['post'] ?? []);
        $topic = $this->topicInRoot($root, (string) ($postData['topic_guid'] ?? ''));
        if ($topic['locked']) {
            throw new ApiError(403, 'locked', 'This topic is locked.');
        }
        if (!Uuid::isValid((string) ($postData['guid'] ?? '')) || trim((string) ($postData['body'] ?? '')) === '') {
            throw new ApiError(422, 'invalid', 'The post is empty or has no ID.');
        }
        if (mb_strlen((string) $postData['body']) > 60000) {
            throw new ApiError(422, 'too_long', 'Posts are limited to 60,000 characters.');
        }
        $author = $this->resolveAuthor(['site_url' => $conn['base_url'], 'site_name' => $conn['site_name']] + (array) ($postData['author'] ?? []));
        $this->importPost($topic, $postData, $author);
        return ['status' => 'accepted'];
    }

    /** Edits and deletes are only accepted from the site the post's author lives on. */
    public function acceptChange(array $conn, string $rootGuid, string $postGuid, string $action, array $data): array
    {
        $root = $this->subscribedRoot($conn, $rootGuid);
        $post = Uuid::isValid($postGuid) ? $this->db->one(
            'SELECT p.*, ru.remote_site_id FROM posts p LEFT JOIN remote_users ru ON ru.id = p.author_remote_id
              WHERE p.guid = ?::uuid AND p.deleted_at IS NULL',
            [$postGuid],
        ) : null;
        if ($post === null) {
            throw new ApiError(404, 'unknown_post', 'That post does not exist here.');
        }
        $this->topicInRoot($root, (string) $this->db->value('SELECT guid FROM topics WHERE id = ?', [$post['topic_id']]));
        if ((int) $post['remote_site_id'] !== (int) $conn['remote_site_id']) {
            throw new ApiError(403, 'not_author_site', 'Only the site the author belongs to can change this post.');
        }
        $this->importing = true;
        try {
            if ($action === 'delete') {
                $this->posts->delete(null, (int) $post['id']);
            } else {
                $body = trim((string) ($data['body'] ?? ''));
                if ($body === '' || mb_strlen($body) > 60000) {
                    throw new ApiError(422, 'invalid', 'Posts are 1 to 60,000 characters.');
                }
                $this->posts->edit(null, (int) $post['id'], $body);
            }
        } finally {
            $this->importing = false;
        }
        // The edit hooks are skipped while importing, so record the change here.
        $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$post['topic_id']]);
        if ($action === 'delete') {
            $wholeTopic = (int) $topic['first_post_id'] === (int) $post['id'];
            $this->emit((int) $topic['node_id'], $wholeTopic ? 'topic.deleted' : 'post.deleted', ['guid' => $wholeTopic ? $topic['guid'] : $post['guid']]);
        } else {
            $this->emit((int) $topic['node_id'], 'post.edited', ['post' => $this->postPayload((int) $post['id'])]);
        }
        return ['status' => 'accepted'];
    }

    // =====================================================================
    // Storing topics and posts that came from another site (both sides)
    // =====================================================================

    /**
     * @param array<string, mixed> $node local node row
     * @param array<string, mixed> $topicData
     * @param list<array<string, mixed>> $postsData
     * @param array{user_id: ?int, remote_id: ?int}|null $forcedAuthor used by the owner for submissions
     */
    private function importTopicWithPosts(array $node, array $topicData, array $postsData, ?array $forcedAuthor = null): void
    {
        $guid = (string) ($topicData['guid'] ?? '');
        if (!Uuid::isValid($guid)) {
            return;
        }
        $author = $forcedAuthor ?? $this->resolveAuthor((array) ($topicData['author'] ?? []));
        $title = mb_substr(trim((string) ($topicData['title'] ?? '')), 0, 150) ?: 'Untitled';
        $created = false;
        $topic = $this->db->transaction(function (Database $db) use ($node, $guid, $topicData, $author, $title, &$created): array {
            $existing = $db->one('SELECT * FROM topics WHERE guid = ?::uuid', [$guid]);
            if ($existing !== null) {
                $db->run(
                    'UPDATE topics SET title = ?, slug = ?, node_id = ?, locked = ?, pinned = ? WHERE id = ?',
                    [$title, \AllianceBB\Core\Slug::make($title), $node['id'], (bool) ($topicData['locked'] ?? false), (bool) ($topicData['pinned'] ?? false), $existing['id']],
                );
                return $db->one('SELECT * FROM topics WHERE id = ?', [$existing['id']]);
            }
            $created = true;
            return $db->one(
                'INSERT INTO topics (guid, node_id, title, slug, author_user_id, author_remote_id, locked, pinned, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?::timestamptz, now())) RETURNING *',
                [
                    $guid, $node['id'], $title, \AllianceBB\Core\Slug::make($title), $author['user_id'], $author['remote_id'],
                    (bool) ($topicData['locked'] ?? false), (bool) ($topicData['pinned'] ?? false), $topicData['created_at'] ?? null,
                ],
            );
        });

        $first = true;
        foreach ($postsData as $postData) {
            $this->importPost($topic, $postData, $first && $forcedAuthor !== null ? $forcedAuthor : null, $created && $first);
            $first = false;
        }
        $this->posts->refreshTopic((int) $topic['id']);
        $this->nodes->refreshStats((int) $node['id']);
        if ($created) {
            $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$topic['id']]);
            $firstPost = $topic['first_post_id'] ? $this->db->one('SELECT * FROM posts WHERE id = ?', [$topic['first_post_id']]) : null;
            if ($firstPost !== null) {
                $this->hooks->doAction('topic.created', $topic, $firstPost, null);
            }
            $this->live->publish('node/' . $node['id'], 'topic.created', ['topic_id' => (int) $topic['id']]);
        }
    }

    /**
     * @param array<string, mixed> $topic local topic row
     * @param array<string, mixed> $postData
     * @param array{user_id: ?int, remote_id: ?int}|null $forcedAuthor
     * @param bool $quiet true for the first post of a new topic (the topic event covers it)
     */
    private function importPost(array $topic, array $postData, ?array $forcedAuthor = null, bool $quiet = false): void
    {
        $guid = (string) ($postData['guid'] ?? '');
        if (!Uuid::isValid($guid)) {
            return;
        }
        $existing = $this->db->one('SELECT * FROM posts WHERE guid = ?::uuid', [$guid]);
        if ($existing !== null) {
            // Our own post coming back from the owner: it has been accepted.
            if (in_array($existing['federation_state'], ['pending', 'rejected'], true)) {
                $this->db->run("UPDATE posts SET federation_state = 'confirmed', federation_error = NULL WHERE id = ?", [$existing['id']]);
                $this->live->publish('topic/' . $existing['topic_id'], 'post.edited', ['post_id' => (int) $existing['id']]);
            }
            return;
        }
        $author = $forcedAuthor ?? $this->resolveAuthor((array) ($postData['author'] ?? []));
        $this->importing = true;
        try {
            $post = $this->posts->insertImportedPost([
                'guid' => $guid,
                'topic_id' => $topic['id'],
                'author_user_id' => $author['user_id'],
                'author_remote_id' => $author['remote_id'],
                'body' => mb_substr((string) ($postData['body'] ?? ''), 0, 60000),
                'created_at' => $postData['created_at'] ?? null,
                'edited_at' => $postData['edited_at'] ?? null,
                'federation_state' => $author['remote_id'] === null ? 'confirmed' : 'remote',
            ]);
        } finally {
            $this->importing = false;
        }
        if ($post === null || $quiet) {
            return;
        }
        $this->posts->refreshTopic((int) $topic['id']);
        $this->nodes->refreshStats((int) $topic['node_id']);
        $this->hooks->doAction('post.created', $post, $topic, null);
        $this->live->publish('topic/' . $topic['id'], 'post.created', ['post_id' => (int) $post['id']]);
        $this->live->publish('node/' . $topic['node_id'], 'topic.bumped', ['topic_id' => (int) $topic['id']]);
    }

    // =====================================================================
    // Subscriber side: cloning and syncing
    // =====================================================================

    /** @return array<string, mixed> another site's public info and shareable sections */
    public function discover(string $url): array
    {
        $url = self::normalizeUrl($url);
        $res = $this->http->request('GET', $url . self::API . '/info');
        if ($res['status'] !== 200 || ($res['json']['software'] ?? '') !== 'AllianceBB') {
            throw new RuntimeException('That address is not an AllianceBB site with sharing turned on.');
        }
        return $res['json'];
    }

    /** Clone a section using a code from the owner's admin. Returns the new local node id. */
    public function cloneFromCode(string $code, ?int $parentId): int
    {
        $data = self::parseCode($code);
        $site = $this->remoteSite($data['url'], $data['name']);
        $conn = $this->db->one('SELECT * FROM connections WHERE public_id = ?', [$data['key']]);
        if ($conn === null) {
            $conn = $this->db->one(
                "INSERT INTO connections (remote_site_id, role, public_id, secret) VALUES (?, 'owner', ?, ?) RETURNING *",
                [$site['id'], $data['key'], $data['secret']],
            );
        } elseif ($conn['role'] !== 'owner') {
            throw new InvalidArgumentException('That code was made by this site. Paste it into the other site.');
        } else {
            $this->db->run("UPDATE connections SET secret = ?, status = 'active' WHERE id = ?", [$data['secret'], $conn['id']]);
        }
        return $this->cloneSection((int) $conn['id'], $data['node'], $parentId);
    }

    /** Public mode on the other site: create a key there, then clone. */
    public function cloneFromUrl(string $url, string $nodeGuid, ?int $parentId): int
    {
        $info = $this->discover($url);
        if (($info['mode'] ?? '') !== 'public') {
            throw new InvalidArgumentException('That site only shares with sites its admin invites. Ask its admin for a connection code.');
        }
        $res = $this->http->request('POST', self::normalizeUrl($url) . self::API . '/register', (string) json_encode([
            'name' => $this->selfName(), 'base_url' => $this->selfUrl(), 'webhook_url' => $this->webhookUrl(),
        ]));
        if ($res['status'] !== 200 || empty($res['json']['key']) || empty($res['json']['secret'])) {
            throw new RuntimeException('The other site did not accept the connection: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        $site = $this->remoteSite((string) ($info['url'] ?? $url), (string) ($info['name'] ?? ''));
        $conn = $this->db->one(
            "INSERT INTO connections (remote_site_id, role, public_id, secret, created_via) VALUES (?, 'owner', ?, ?, 'public') RETURNING *",
            [$site['id'], $res['json']['key'], $res['json']['secret']],
        );
        return $this->cloneSection((int) $conn['id'], $nodeGuid, $parentId);
    }

    private function cloneSection(int $connectionId, string $rootGuid, ?int $parentId): int
    {
        if (!$this->enabled()) {
            throw new InvalidArgumentException('Sharing is off on this site. Turn it on under Settings first.');
        }
        if ($this->db->value('SELECT 1 FROM nodes WHERE guid = ?::uuid', [$rootGuid])) {
            throw new InvalidArgumentException('That section is already on this site.');
        }
        $conn = $this->connection($connectionId) ?? throw new RuntimeException('Connection missing.');
        $res = $this->call($conn, 'POST', self::API . '/subscribe', ['node_guid' => $rootGuid, 'webhook_url' => $this->webhookUrl()]);
        if ($res['status'] !== 200) {
            throw new RuntimeException('The other site refused: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        $data = $res['json'];
        $this->applyStructure($conn, $rootGuid, (array) ($data['structure'] ?? []), $parentId);
        foreach ((array) ($data['topics'] ?? []) as $item) {
            $this->applyTopicSnapshot($conn, $rootGuid, (array) $item);
        }
        $this->db->run('UPDATE nodes SET sync_cursor = ? WHERE guid = ?::uuid', [(int) ($data['cursor'] ?? 0), $rootGuid]);
        return (int) $this->db->value('SELECT id FROM nodes WHERE guid = ?::uuid', [$rootGuid]);
    }

    /**
     * Mirror the owner's section tree: create, update and remove local copies.
     * @param list<array<string, mixed>> $structure parents first
     */
    private function applyStructure(array $conn, string $rootGuid, array $structure, ?int $parentIdForRoot = null): void
    {
        $this->db->transaction(function (Database $db) use ($conn, $rootGuid, $structure, $parentIdForRoot): void {
            $seen = [];
            foreach ($structure as $item) {
                $guid = (string) ($item['guid'] ?? '');
                if (!Uuid::isValid($guid)) {
                    continue;
                }
                $isRoot = $guid === $rootGuid;
                $type = ($item['type'] ?? '') === 'category' ? 'category' : 'forum';
                $existing = $db->one('SELECT * FROM nodes WHERE guid = ?::uuid', [$guid]);
                if ($existing !== null && (int) $existing['origin_connection_id'] !== (int) $conn['id']) {
                    continue; // never overwrite a local node or another site's clone
                }
                if ($isRoot) {
                    $parentId = $existing !== null ? $existing['parent_id'] : $parentIdForRoot;
                    if ($type === 'category') {
                        $parentId = null; // categories always sit at the top level
                    }
                } else {
                    $parentId = $db->value('SELECT id FROM nodes WHERE guid = ?::uuid', [(string) ($item['parent_guid'] ?? '')]);
                    if ($parentId === null) {
                        continue;
                    }
                }
                $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 120) ?: 'Untitled';
                $fields = [$parentId, $type, $title, \AllianceBB\Core\Slug::make($title), mb_substr((string) ($item['description'] ?? ''), 0, 300), (int) ($item['position'] ?? 0)];
                if ($existing !== null) {
                    $db->run('UPDATE nodes SET parent_id = ?, type = ?, title = ?, slug = ?, description = ?, position = ? WHERE id = ?', [...$fields, $existing['id']]);
                } else {
                    $db->run(
                        'INSERT INTO nodes (parent_id, type, title, slug, description, position, guid, origin_connection_id, origin_root_guid)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [...$fields, $guid, $conn['id'], $rootGuid],
                    );
                }
                $seen[] = $guid;
            }
            // Forums the owner removed from the section.
            $local = $db->all('SELECT id, guid FROM nodes WHERE origin_connection_id = ? AND origin_root_guid = ?::uuid', [$conn['id'], $rootGuid]);
            foreach ($local as $row) {
                if (!in_array($row['guid'], $seen, true) && $row['guid'] !== $rootGuid) {
                    $db->run('DELETE FROM nodes WHERE id = ?', [$row['id']]);
                }
            }
        });
    }

    /** @param array<string, mixed> $item {topic, posts} */
    private function applyTopicSnapshot(array $conn, string $rootGuid, array $item): void
    {
        $topicData = (array) ($item['topic'] ?? []);
        $node = $this->clonedNode($conn, $rootGuid, (string) ($topicData['node_guid'] ?? ''));
        if ($node !== null) {
            $this->importTopicWithPosts($node, $topicData, array_values((array) ($item['posts'] ?? [])));
        }
    }

    /** @return array<string, mixed>|null */
    private function clonedNode(array $conn, string $rootGuid, string $guid): ?array
    {
        return Uuid::isValid($guid) ? $this->db->one(
            'SELECT * FROM nodes WHERE guid = ?::uuid AND origin_connection_id = ? AND origin_root_guid = ?::uuid',
            [$guid, $conn['id'], $rootGuid],
        ) : null;
    }

    /** @return list<array<string, mixed>> sections this site has cloned */
    public function clonedRoots(): array
    {
        return $this->db->all(
            "SELECT n.*, c.status AS connection_status, rs.name AS site_name, rs.base_url, rs.handle
               FROM nodes n JOIN connections c ON c.id = n.origin_connection_id JOIN remote_sites rs ON rs.id = c.remote_site_id
              WHERE n.guid = n.origin_root_guid ORDER BY n.id",
        );
    }

    /** Fetch and apply every new event for a cloned section. Returns how many were applied. */
    public function sync(int $rootNodeId): int
    {
        $root = $this->db->one('SELECT * FROM nodes WHERE id = ? AND guid = origin_root_guid', [$rootNodeId]);
        if ($root === null || !$this->enabled()) {
            return 0;
        }
        $conn = $this->connection((int) $root['origin_connection_id']);
        if ($conn === null || $conn['status'] !== 'active') {
            return 0;
        }
        $applied = 0;
        for ($page = 0; $page < 50; $page++) {
            $cursor = (int) $this->db->value('SELECT sync_cursor FROM nodes WHERE id = ?', [$rootNodeId]);
            $res = $this->call($conn, 'GET', self::API . '/nodes/' . $root['guid'] . '/events?after=' . $cursor);
            if ($res['status'] !== 200) {
                throw new RuntimeException('Sync failed: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
            }
            $events = (array) ($res['json']['events'] ?? []);
            foreach ($events as $event) {
                $this->applyEvent($conn, (string) $root['guid'], (string) ($event['type'] ?? ''), (array) ($event['payload'] ?? []));
                $this->db->run('UPDATE nodes SET sync_cursor = GREATEST(sync_cursor, ?) WHERE id = ?', [(int) $event['seq'], $rootNodeId]);
                $applied++;
            }
            if ($events === [] || (int) ($res['json']['latest'] ?? 0) <= (int) end($events)['seq']) {
                break;
            }
        }
        return $applied;
    }

    /** @param array<string, mixed> $payload */
    private function applyEvent(array $conn, string $rootGuid, string $type, array $payload): void
    {
        switch ($type) {
            case 'structure':
                $this->applyStructure($conn, $rootGuid, (array) ($payload['nodes'] ?? []));
                break;
            case 'topic.created':
            case 'topic.snapshot':
                $this->applyTopicSnapshot($conn, $rootGuid, [
                    'topic' => $payload['topic'] ?? [],
                    'posts' => $payload['posts'] ?? (isset($payload['post']) ? [$payload['post']] : []),
                ]);
                break;
            case 'topic.updated':
                $topicData = (array) ($payload['topic'] ?? []);
                $node = $this->clonedNode($conn, $rootGuid, (string) ($topicData['node_guid'] ?? ''));
                $topic = $this->localTopic((string) ($topicData['guid'] ?? ''));
                if ($node !== null && $topic !== null) {
                    $this->db->run(
                        'UPDATE topics SET title = ?, locked = ?, pinned = ?, node_id = ? WHERE id = ?',
                        [mb_substr((string) $topicData['title'], 0, 150), (bool) $topicData['locked'], (bool) $topicData['pinned'], $node['id'], $topic['id']],
                    );
                    $this->nodes->refreshStats((int) $topic['node_id']);
                    $this->nodes->refreshStats((int) $node['id']);
                }
                break;
            case 'post.created':
                $postData = (array) ($payload['post'] ?? []);
                $topic = $this->localTopic((string) ($postData['topic_guid'] ?? ''));
                if ($topic !== null) {
                    $this->importPost($topic, $postData);
                }
                break;
            case 'post.edited':
                $postData = (array) ($payload['post'] ?? []);
                $post = $this->localPost((string) ($postData['guid'] ?? ''));
                if ($post !== null && $post['author_remote_id'] !== null) {
                    $this->posts->applyImportedEdit((int) $post['id'], mb_substr((string) $postData['body'], 0, 60000), $postData['edited_at'] ?? null);
                    $this->live->publish('topic/' . $post['topic_id'], 'post.edited', ['post_id' => (int) $post['id']]);
                }
                break;
            case 'post.deleted':
                $post = $this->localPost((string) ($payload['guid'] ?? ''));
                if ($post !== null) {
                    $this->db->run('UPDATE posts SET deleted_at = now() WHERE id = ?', [$post['id']]);
                    $this->posts->refreshTopic((int) $post['topic_id']);
                    $nodeId = (int) $this->db->value('SELECT node_id FROM topics WHERE id = ?', [$post['topic_id']]);
                    $this->nodes->refreshStats($nodeId);
                    $this->live->publish('topic/' . $post['topic_id'], 'post.deleted', ['post_id' => (int) $post['id']]);
                }
                break;
            case 'topic.deleted':
                $topic = $this->localTopic((string) ($payload['guid'] ?? ''));
                if ($topic !== null) {
                    $this->db->run('UPDATE topics SET deleted_at = now() WHERE id = ?', [$topic['id']]);
                    $this->nodes->refreshStats((int) $topic['node_id']);
                    $this->live->publish('node/' . $topic['node_id'], 'topic.deleted', ['topic_id' => (int) $topic['id']]);
                }
                break;
        }
    }

    /** @return array<string, mixed>|null */
    private function localTopic(string $guid): ?array
    {
        return Uuid::isValid($guid) ? $this->db->one('SELECT * FROM topics WHERE guid = ?::uuid AND deleted_at IS NULL', [$guid]) : null;
    }

    /** @return array<string, mixed>|null */
    private function localPost(string $guid): ?array
    {
        return Uuid::isValid($guid) ? $this->db->one('SELECT * FROM posts WHERE guid = ?::uuid AND deleted_at IS NULL', [$guid]) : null;
    }

    /** A webhook from an owner: fetch the new events now, or queue it if that fails. */
    public function receiveWebhook(array $conn, array $data): void
    {
        $root = $this->clonedNode($conn, (string) ($data['root_guid'] ?? ''), (string) ($data['root_guid'] ?? ''));
        if ($root === null) {
            throw new ApiError(404, 'not_cloned', 'This site does not clone that section.');
        }
        if ((int) $root['sync_cursor'] >= (int) ($data['latest_seq'] ?? 0)) {
            return;
        }
        try {
            $this->sync((int) $root['id']);
        } catch (\Throwable $e) {
            $this->jobs->push('sync', ['node_id' => (int) $root['id']], 'sync:' . $root['id'], 5);
        }
    }

    /** Stop receiving a section and remove the local copy. */
    public function disconnect(int $rootNodeId): void
    {
        $root = $this->db->one('SELECT * FROM nodes WHERE id = ? AND guid = origin_root_guid', [$rootNodeId]);
        if ($root === null) {
            throw new InvalidArgumentException('That is not a cloned section.');
        }
        $conn = $this->connection((int) $root['origin_connection_id']);
        if ($conn !== null && $conn['status'] === 'active') {
            try {
                $this->call($conn, 'POST', self::API . '/nodes/' . $root['guid'] . '/unsubscribe', []);
            } catch (\Throwable) {
                // The owner stops sending webhooks once they fail; nothing else to do.
            }
        }
        $this->db->run('DELETE FROM nodes WHERE id = ?', [$rootNodeId]);
    }

    // =====================================================================
    // Hide and block (this site only)
    // =====================================================================

    public function hidePost(int $postId, ?int $moderatorId): void
    {
        $this->db->run('INSERT INTO local_hides (post_id, hidden_by) VALUES (?, ?) ON CONFLICT DO NOTHING', [$postId, $moderatorId]);
        $topicId = $this->db->value('SELECT topic_id FROM posts WHERE id = ?', [$postId]);
        $this->live->publish('topic/' . $topicId, 'post.deleted', ['post_id' => $postId]);
    }

    public function unhidePost(int $postId): void
    {
        $this->db->run('DELETE FROM local_hides WHERE post_id = ?', [$postId]);
    }

    public function blockRemoteUser(int $remoteUserId, ?int $moderatorId, bool $blocked = true): void
    {
        $this->db->run(
            'UPDATE remote_users SET blocked_at = CASE WHEN ? THEN now() ELSE NULL END, blocked_by = ? WHERE id = ?',
            [$blocked, $blocked ? $moderatorId : null, $remoteUserId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function hiddenPosts(): array
    {
        return $this->db->all(
            "SELECT p.id, p.body, t.title AS topic_title, COALESCE(u.username, ru.username || '@' || rs.handle) AS author, h.created_at
               FROM local_hides h JOIN posts p ON p.id = h.post_id JOIN topics t ON t.id = p.topic_id
               LEFT JOIN users u ON u.id = p.author_user_id
               LEFT JOIN remote_users ru ON ru.id = p.author_remote_id LEFT JOIN remote_sites rs ON rs.id = ru.remote_site_id
              ORDER BY h.created_at DESC LIMIT 100",
        );
    }

    /** @return list<array<string, mixed>> */
    public function blockedUsers(): array
    {
        return $this->db->all(
            "SELECT ru.id, ru.username || '@' || rs.handle AS handle, ru.blocked_at
               FROM remote_users ru JOIN remote_sites rs ON rs.id = ru.remote_site_id
              WHERE ru.blocked_at IS NOT NULL ORDER BY ru.blocked_at DESC",
        );
    }

    /** @return list<array<string, mixed>> local sections with their sharing state */
    public function localSections(): array
    {
        return $this->db->all(
            'SELECT n.id, n.guid, n.type, n.title, n.parent_id, n.shareable,
                    (SELECT count(*) FROM subscriptions s WHERE s.node_id = n.id) AS subscriber_count
               FROM nodes n WHERE n.origin_connection_id IS NULL ORDER BY n.parent_id NULLS FIRST, n.position, n.id',
        );
    }

    public function setShareable(int $nodeId, bool $shareable): void
    {
        $this->db->run('UPDATE nodes SET shareable = ? WHERE id = ? AND origin_connection_id IS NULL', [$shareable, $nodeId]);
        if (!$shareable) {
            $this->db->run('DELETE FROM subscriptions WHERE node_id = ?', [$nodeId]);
        }
    }

    // =====================================================================
    // Jobs and hooks
    // =====================================================================

    public function registerJobs(): void
    {
        $this->jobs->handle('webhook', function (array $p): void {
            $conn = $this->connection((int) $p['connection_id']);
            if ($conn === null || $conn['status'] !== 'active' || !$conn['webhook_url'] || !$this->enabled()) {
                return;
            }
            $body = (string) json_encode(['root_guid' => $p['root_guid'], 'latest_seq' => $p['latest_seq']]);
            $parts = parse_url((string) $conn['webhook_url']);
            $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $res = $this->http->request('POST', (string) $conn['webhook_url'], $body, Signer::headers($conn['public_id'], $conn['secret'], 'POST', $target, $body));
            if ($res['status'] === 404 || $res['status'] === 401) {
                throw new PermanentFailure('Webhook refused with HTTP ' . $res['status'] . ': ' . ($res['json']['message'] ?? ''));
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new RuntimeException('Webhook got HTTP ' . $res['status']);
            }
        });
        $this->jobs->handle('sync', function (array $p): void {
            $this->sync((int) $p['node_id']);
        });
        $this->jobs->handle('submit', fn (array $p) => $this->submit($p));
    }

    /** Queue a catch-up sync for every cloned section. Run by the worker every few minutes. */
    public function queueCatchUp(): void
    {
        foreach ($this->clonedRoots() as $root) {
            if ($root['connection_status'] === 'active') {
                $this->jobs->push('sync', ['node_id' => (int) $root['id']], 'sync:' . $root['id']);
            }
        }
        $this->db->run("DELETE FROM fed_nonces WHERE created_at < now() - interval '15 minutes'");
    }

    /** Send something a local user did in a cloned section to the section's owner. */
    private function submit(array $p): void
    {
        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [(int) $p['post_id']]);
        if ($post === null) {
            return;
        }
        $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$post['topic_id']]);
        $node = $topic ? $this->db->one('SELECT * FROM nodes WHERE id = ?', [$topic['node_id']]) : null;
        if ($node === null || $node['origin_connection_id'] === null) {
            return;
        }
        $conn = $this->connection((int) $node['origin_connection_id']);
        if ($conn === null || $conn['status'] !== 'active') {
            $this->markRejected((int) $post['id'], 'The connection to ' . ($conn['site_name'] ?? 'the owner') . ' is no longer active.');
            return;
        }
        $base = self::API . '/nodes/' . $node['origin_root_guid'];
        [$path, $data] = match ($p['action']) {
            'create_topic' => [$base . '/topics', ['topic' => $this->topicPayload((int) $topic['id']), 'post' => $this->postPayload((int) $post['id'])]],
            'create_post' => [$base . '/posts', ['post' => $this->postPayload((int) $post['id'])]],
            'edit' => [$base . '/posts/' . $post['guid'] . '/edit', ['body' => $post['body']]],
            'delete' => [$base . '/posts/' . $post['guid'] . '/delete', []],
            default => throw new PermanentFailure('Unknown submit action'),
        };
        $res = $this->call($conn, 'POST', $path, $data);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            if (in_array($p['action'], ['create_topic', 'create_post'], true)) {
                $this->db->run("UPDATE posts SET federation_state = 'confirmed', federation_error = NULL WHERE id = ? AND federation_state = 'pending'", [$post['id']]);
                $this->live->publish('topic/' . $post['topic_id'], 'post.edited', ['post_id' => (int) $post['id']]);
            }
            return;
        }
        if (in_array($res['status'], [409, 429], true) || $res['status'] >= 500) {
            throw new RuntimeException('Owner answered HTTP ' . $res['status'] . '; will retry.');
        }
        $message = (string) ($res['json']['message'] ?? 'HTTP ' . $res['status']);
        if (in_array($p['action'], ['create_topic', 'create_post'], true)) {
            $this->markRejected((int) $post['id'], $message);
        }
        throw new PermanentFailure($message);
    }

    private function markRejected(int $postId, string $message): void
    {
        $this->db->run("UPDATE posts SET federation_state = 'rejected', federation_error = ? WHERE id = ?", [mb_substr($message, 0, 300), $postId]);
        $topicId = $this->db->value('SELECT topic_id FROM posts WHERE id = ?', [$postId]);
        $this->live->publish('topic/' . $topicId, 'post.edited', ['post_id' => $postId]);
    }

    /** @return array<string, mixed>|null */
    private function nodeOfTopic(array $topic): ?array
    {
        return $this->db->one('SELECT * FROM nodes WHERE id = ?', [$topic['node_id']]);
    }

    /**
     * Listen to forum activity. Changes in a local shared section become events
     * for subscribers; posts written here in a cloned section go to the owner.
     */
    public function registerHooks(): void
    {
        $h = $this->hooks;

        $h->addAction('topic.created', function (array $topic, array $post, ?Context $ctx): void {
            $node = $this->nodeOfTopic($topic);
            if ($node === null) {
                return;
            }
            if ($node['origin_connection_id'] !== null) {
                if (!$this->importing && $post['federation_state'] === 'local') {
                    $this->db->run("UPDATE posts SET federation_state = 'pending' WHERE id = ?", [$post['id']]);
                    $this->jobs->push('submit', ['post_id' => (int) $post['id'], 'action' => 'create_topic']);
                }
                return;
            }
            $this->emit((int) $node['id'], 'topic.created', [
                'topic' => $this->topicPayload((int) $topic['id']),
                'post' => $this->postPayload((int) $post['id']),
            ]);
        });

        $h->addAction('post.created', function (array $post, array $topic, ?Context $ctx): void {
            $node = $this->nodeOfTopic($topic);
            if ($node === null) {
                return;
            }
            if ($node['origin_connection_id'] !== null) {
                if (!$this->importing && $post['federation_state'] === 'local') {
                    $this->db->run("UPDATE posts SET federation_state = 'pending' WHERE id = ?", [$post['id']]);
                    $this->jobs->push('submit', ['post_id' => (int) $post['id'], 'action' => 'create_post']);
                }
                return;
            }
            $this->emit((int) $node['id'], 'post.created', ['post' => $this->postPayload((int) $post['id'])]);
        });

        $h->addAction('post.edited', function (?array $post, ?Context $ctx): void {
            if ($post === null || $this->importing) {
                return;
            }
            $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$post['topic_id']]);
            $node = $topic ? $this->nodeOfTopic($topic) : null;
            if ($node === null) {
                return;
            }
            if ($node['origin_connection_id'] !== null) {
                if ($post['author_remote_id'] === null && in_array($post['federation_state'], ['pending', 'confirmed'], true)) {
                    $this->jobs->push('submit', ['post_id' => (int) $post['id'], 'action' => 'edit']);
                }
                return;
            }
            $this->emit((int) $node['id'], 'post.edited', ['post' => $this->postPayload((int) $post['id'])]);
        });

        $deleted = function (array $post, ?array $topic, bool $wholeTopic): void {
            if ($this->importing || $topic === null) {
                return;
            }
            $node = $this->nodeOfTopic($topic);
            if ($node === null) {
                return;
            }
            if ($node['origin_connection_id'] !== null) {
                if ($post['author_remote_id'] === null && in_array($post['federation_state'], ['pending', 'confirmed'], true)) {
                    $this->jobs->push('submit', ['post_id' => (int) $post['id'], 'action' => 'delete']);
                }
                return;
            }
            $this->emit((int) $node['id'], $wholeTopic ? 'topic.deleted' : 'post.deleted', ['guid' => $wholeTopic ? $topic['guid'] : $post['guid']]);
        };
        $h->addAction('post.deleted', function (array $post) use ($deleted): void {
            $deleted($post, $this->db->one('SELECT * FROM topics WHERE id = ?', [$post['topic_id']]), false);
        });
        $h->addAction('topic.deleted', function (array $topic) use ($deleted): void {
            $first = $this->db->one('SELECT * FROM posts WHERE id = ?', [$topic['first_post_id']]);
            if ($first !== null) {
                $deleted($first, $topic, true);
            }
        });

        $topicUpdated = function (int $topicId): void {
            $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$topicId]);
            $node = $topic ? $this->nodeOfTopic($topic) : null;
            if ($node !== null && $node['origin_connection_id'] === null) {
                $this->emit((int) $node['id'], 'topic.updated', ['topic' => $this->topicPayload($topicId)]);
            }
        };
        $h->addAction('topic.locked', fn (int $id) => $topicUpdated($id));
        $h->addAction('topic.pinned', fn (int $id) => $topicUpdated($id));
        $h->addAction('topic.moved', function (int $topicId, int $fromNodeId, int $toNodeId): void {
            $fromRoots = array_column($this->sharedRootsFor($fromNodeId), null, 'id');
            $toRoots = array_column($this->sharedRootsFor($toNodeId), null, 'id');
            $topic = $this->db->one('SELECT * FROM topics WHERE id = ?', [$topicId]);
            foreach ($toRoots as $id => $root) {
                isset($fromRoots[$id])
                    ? $this->emitToRoot($root, 'topic.updated', ['topic' => $this->topicPayload($topicId)])
                    : $this->emitToRoot($root, 'topic.snapshot', $this->topicWithPosts($topicId));
            }
            foreach ($fromRoots as $id => $root) {
                if (!isset($toRoots[$id])) {
                    $this->emitToRoot($root, 'topic.deleted', ['guid' => $topic['guid']]);
                }
            }
        });

        $structureChanged = function (?array $node): void {
            if ($node === null || $node['origin_connection_id'] !== null) {
                return;
            }
            $start = $node['parent_id'] ?? null;
            $ids = array_filter([(int) $node['id'], $start === null ? null : (int) $start]);
            $done = [];
            foreach ($ids as $id) {
                foreach ($this->sharedRootsFor($id) as $root) {
                    if (!isset($done[$root['id']])) {
                        $done[$root['id']] = true;
                        $this->emitToRoot($root, 'structure', ['nodes' => $this->structure($root)]);
                    }
                }
            }
        };
        $h->addAction('node.created', $structureChanged);
        $h->addAction('node.updated', $structureChanged);
        $h->addAction('node.deleted', $structureChanged);

        // Permissions: content from other sites can only be hidden here, and
        // cloned sections are moderated by their owner.
        $h->addFilter('permission', function (bool $allowed, string $ability, ?array $subject, ?Context $ctx): bool {
            if (!$allowed && !in_array($ability, ['post.hide', 'post.block'], true)) {
                return false;
            }
            switch ($ability) {
                case 'post.edit':
                case 'post.delete':
                    if (!empty($subject['author_remote_id'])) {
                        return false;
                    }
                    if (!empty($subject['topic_id']) && ($subject['federation_state'] ?? '') === 'rejected') {
                        return $allowed;
                    }
                    return $allowed && !(isset($subject['topic_id']) && $this->topicIsClonedAndOffline((int) $subject['topic_id']));
                case 'topic.moderate':
                    return $allowed && empty($this->nodeOfTopic($subject ?? [])['origin_connection_id']);
                case 'topic.create':
                    return $allowed && (empty($subject['origin_connection_id']) || $this->connectionUsable((int) $subject['origin_connection_id']));
                case 'post.reply':
                    $node = $this->nodeOfTopic($subject ?? []);
                    return $allowed && (empty($node['origin_connection_id']) || $this->connectionUsable((int) $node['origin_connection_id']));
                case 'post.hide':
                    return $ctx !== null && $ctx->isModerator() && empty($subject['is_hidden']);
                case 'post.block':
                    return $ctx !== null && $ctx->isModerator() && !empty($subject['author_remote_id']);
            }
            return $allowed;
        });
    }

    private function connectionUsable(int $connectionId): bool
    {
        return $this->enabled() && $this->db->value("SELECT 1 FROM connections WHERE id = ? AND status = 'active'", [$connectionId]) !== null;
    }

    private function topicIsClonedAndOffline(int $topicId): bool
    {
        $connId = $this->db->value(
            'SELECT n.origin_connection_id FROM topics t JOIN nodes n ON n.id = t.node_id WHERE t.id = ?',
            [$topicId],
        );
        return $connId !== null && !$this->connectionUsable((int) $connId);
    }

    /** Background jobs that gave up, for the admin page. */
    public function failedJobs(): array
    {
        return $this->jobs->recentFailures();
    }

    public function newSecretFor(int $connectionId): void
    {
        $this->db->run('UPDATE connections SET secret = ? WHERE id = ?', [Signer::newSecret(), $connectionId]);
    }

    /** Turn an exception into an API error response body. */
    public static function apiError(\Throwable $e): array
    {
        return $e instanceof ApiError
            ? ['status' => $e->status, 'body' => ['error' => $e->errorCode, 'message' => $e->getMessage()]]
            : ['status' => 500, 'body' => ['error' => 'server_error', 'message' => 'Something went wrong on this site.']];
    }
}
