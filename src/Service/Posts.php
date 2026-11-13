<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Context;
use AllianceBB\Core\Database;
use AllianceBB\Core\Hooks;
use AllianceBB\Core\Slug;
use AllianceBB\Core\Uuid;
use AllianceBB\Live\Broadcaster;
use InvalidArgumentException;

/** Topics and posts: creating, replying, editing, deleting and topic moderation. */
final class Posts
{
    public const POSTS_PER_PAGE = 25;
    public const TOPICS_PER_PAGE = 30;
    private const POST_SELECT = "SELECT p.*,
            COALESCE(u.username, ru.username) AS username,
            COALESCE(u.display_name, ru.display_name) AS display_name,
            COALESCE(u.avatar_url, ru.avatar_url) AS avatar_url,
            u.role AS author_role, u.post_count AS author_post_count,
            CASE WHEN ru.id IS NOT NULL THEN ru.username || '@' || rs.handle END AS author_handle,
            (ru.id IS NOT NULL) AS is_remote, ru.blocked_at AS author_blocked_at,
            (h.post_id IS NOT NULL) AS is_hidden
          FROM posts p
          LEFT JOIN users u ON u.id = p.author_user_id
          LEFT JOIN remote_users ru ON ru.id = p.author_remote_id
          LEFT JOIN remote_sites rs ON rs.id = ru.remote_site_id
          LEFT JOIN local_hides h ON h.post_id = p.id";

    /** Hidden on this site, or written by a blocked remote user. */
    private const VISIBLE = 'h.post_id IS NULL AND ru.blocked_at IS NULL';

    private const TOPIC_SELECT = "SELECT t.*,
            COALESCE(u.username, ru.username || '@' || rs.handle) AS author_username,
            COALESCE(lu.username, lru.username || '@' || lrs.handle) AS last_username,
            lp.created_at AS last_post_created
          FROM topics t
          LEFT JOIN users u ON u.id = t.author_user_id
          LEFT JOIN remote_users ru ON ru.id = t.author_remote_id
          LEFT JOIN remote_sites rs ON rs.id = ru.remote_site_id
          LEFT JOIN posts lp ON lp.id = t.last_post_id
          LEFT JOIN users lu ON lu.id = lp.author_user_id
          LEFT JOIN remote_users lru ON lru.id = lp.author_remote_id
          LEFT JOIN remote_sites lrs ON lrs.id = lru.remote_site_id";

    /** A topic is hidden here when its first post is hidden or its author is blocked. */
    private const TOPIC_VISIBLE = 'ru.blocked_at IS NULL AND NOT EXISTS (SELECT 1 FROM local_hides x WHERE x.post_id = t.first_post_id)';

    public function __construct(
        private readonly Database $db,
        private readonly Hooks $hooks,
        private readonly Markup $markup,
        private readonly Nodes $nodes,
        private readonly Broadcaster $live,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function topic(int $id): ?array
    {
        return $this->db->one(
            'SELECT t.* FROM topics t LEFT JOIN remote_users ru ON ru.id = t.author_remote_id
              WHERE t.id = ? AND t.deleted_at IS NULL AND ' . self::TOPIC_VISIBLE,
            [$id],
        );
    }

    /** @return array<string, mixed>|null a visible post */
    public function post(int $id): ?array
    {
        return $this->db->one(self::POST_SELECT . ' WHERE p.id = ? AND p.deleted_at IS NULL AND ' . self::VISIBLE, [$id]);
    }

    /** @return array<string, mixed>|null any post, including hidden and deleted ones */
    public function postAnyState(int $id): ?array
    {
        return $this->db->one(self::POST_SELECT . ' WHERE p.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function topicsIn(int $nodeId, int $page = 1): array
    {
        return $this->db->all(
            self::TOPIC_SELECT . ' WHERE t.node_id = ? AND t.deleted_at IS NULL AND ' . self::TOPIC_VISIBLE . '
              ORDER BY t.pinned DESC, t.last_post_at DESC LIMIT ? OFFSET ?',
            [$nodeId, self::TOPICS_PER_PAGE, max(0, $page - 1) * self::TOPICS_PER_PAGE],
        );
    }

    /** @return array<string, mixed>|null one topic row shaped like topicsIn() rows */
    public function topicRow(int $topicId): ?array
    {
        return $this->db->one(
            self::TOPIC_SELECT . ' WHERE t.id = ? AND t.deleted_at IS NULL AND ' . self::TOPIC_VISIBLE,
            [$topicId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function postsIn(int $topicId, int $page = 1): array
    {
        return $this->db->all(
            self::POST_SELECT . ' WHERE p.topic_id = ? AND p.deleted_at IS NULL AND ' . self::VISIBLE . '
              ORDER BY p.id LIMIT ? OFFSET ?',
            [$topicId, self::POSTS_PER_PAGE, max(0, $page - 1) * self::POSTS_PER_PAGE],
        );
    }

    /** @return list<array<string, mixed>> visible posts with an id above $afterId, oldest first */
    public function postsAfter(int $topicId, int $afterId, int $limit = self::POSTS_PER_PAGE): array
    {
        return $this->db->all(
            self::POST_SELECT . ' WHERE p.topic_id = ? AND p.id > ? AND p.deleted_at IS NULL AND ' . self::VISIBLE . '
              ORDER BY p.id LIMIT ?',
            [$topicId, $afterId, $limit],
        );
    }

    public function pageOfPost(int $topicId, int $postId): int
    {
        $before = (int) $this->db->value(
            'SELECT count(*) FROM posts WHERE topic_id = ? AND id < ? AND deleted_at IS NULL',
            [$topicId, $postId],
        );
        return intdiv($before, self::POSTS_PER_PAGE) + 1;
    }

    /** @return array{topic: array<string, mixed>, post: array<string, mixed>} */
    public function createTopic(Context $ctx, int $nodeId, string $title, string $body): array
    {
        $node = $this->nodes->find($nodeId);
        if ($node === null || $node['type'] !== 'forum') {
            throw new InvalidArgumentException('Topics can only be started in a forum.');
        }
        $title = trim((string) $this->hooks->applyFilters('topic.title', trim($title), $ctx));
        if ($title === '' || mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Topic titles are 1 to 150 characters.');
        }
        $body = $this->cleanBody($body, $ctx);

        [$topic, $post] = $this->db->transaction(function (Database $db) use ($ctx, $nodeId, $title, $body): array {
            $topic = $db->one(
                'INSERT INTO topics (guid, node_id, title, slug, author_user_id) VALUES (?, ?, ?, ?, ?) RETURNING *',
                [Uuid::v7(), $nodeId, $title, Slug::make($title), $ctx->userId()],
            );
            $post = $this->insertPost($db, (int) $topic['id'], $ctx->userId(), $body);
            $db->run(
                'UPDATE topics SET first_post_id = ?, last_post_id = ?, post_count = 1, last_post_at = now() WHERE id = ?',
                [$post['id'], $post['id'], $topic['id']],
            );
            $db->run(
                'UPDATE nodes SET topic_count = topic_count + 1, post_count = post_count + 1, last_post_id = ? WHERE id = ?',
                [$post['id'], $nodeId],
            );
            return [$db->one('SELECT * FROM topics WHERE id = ?', [$topic['id']]), $post];
        });

        $this->hooks->doAction('topic.created', $topic, $post, $ctx);
        $this->live->publish('node/' . $nodeId, 'topic.created', ['topic_id' => (int) $topic['id']]);
        return ['topic' => $topic, 'post' => $post];
    }

    /** @return array<string, mixed> the new post */
    public function reply(Context $ctx, int $topicId, string $body): array
    {
        $topic = $this->topic($topicId) ?? throw new InvalidArgumentException('That topic no longer exists.');
        $body = $this->cleanBody($body, $ctx);

        $post = $this->db->transaction(function (Database $db) use ($ctx, $topic, $body): array {
            $post = $this->insertPost($db, (int) $topic['id'], $ctx->userId(), $body);
            $db->run(
                'UPDATE topics SET last_post_id = ?, post_count = post_count + 1, last_post_at = now() WHERE id = ?',
                [$post['id'], $topic['id']],
            );
            $db->run(
                'UPDATE nodes SET post_count = post_count + 1, last_post_id = ? WHERE id = ?',
                [$post['id'], $topic['node_id']],
            );
            return $post;
        });

        $this->hooks->doAction('post.created', $post, $topic, $ctx);
        $this->live->publish('topic/' . $topic['id'], 'post.created', ['post_id' => (int) $post['id']]);
        $this->live->publish('node/' . $topic['node_id'], 'topic.bumped', ['topic_id' => (int) $topic['id']]);
        return $post;
    }

    public function edit(?Context $ctx, int $postId, string $body): void
    {
        $post = $this->postAnyState($postId);
        if ($post === null || $post['deleted_at'] !== null) {
            throw new InvalidArgumentException('That post no longer exists.');
        }
        $body = $this->cleanBody($body, $ctx);
        $this->db->run(
            'UPDATE posts SET body = ?, body_html = ?, edited_at = now() WHERE id = ?',
            [$body, $this->markup->render($body), $postId],
        );
        $this->hooks->doAction('post.edited', $this->postAnyState($postId), $ctx);
        $this->live->publish('topic/' . $post['topic_id'], 'post.edited', ['post_id' => $postId]);
    }

    /** Deleting a topic's first post deletes the whole topic. */
    public function delete(?Context $ctx, int $postId): void
    {
        $post = $this->postAnyState($postId);
        if ($post === null || $post['deleted_at'] !== null) {
            throw new InvalidArgumentException('That post no longer exists.');
        }
        $topic = $this->db->one('SELECT * FROM topics WHERE id = ? AND deleted_at IS NULL', [$post['topic_id']])
            ?? throw new InvalidArgumentException('That topic no longer exists.');
        $wholeTopic = (int) $topic['first_post_id'] === $postId;

        $this->db->transaction(function (Database $db) use ($post, $topic, $wholeTopic): void {
            if ($wholeTopic) {
                $db->run('UPDATE topics SET deleted_at = now() WHERE id = ?', [$topic['id']]);
            } else {
                $db->run('UPDATE posts SET deleted_at = now() WHERE id = ?', [$post['id']]);
                $db->run(
                    'UPDATE topics SET post_count = post_count - 1,
                        last_post_id = (SELECT id FROM posts WHERE topic_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1)
                      WHERE id = ?',
                    [$topic['id'], $topic['id']],
                );
            }
            $this->nodes->refreshStats((int) $topic['node_id']);
        });

        if ($wholeTopic) {
            $this->hooks->doAction('topic.deleted', $topic, $ctx);
            $this->live->publish('node/' . $topic['node_id'], 'topic.deleted', ['topic_id' => (int) $topic['id']]);
        } else {
            $this->hooks->doAction('post.deleted', $post, $ctx);
            $this->live->publish('topic/' . $topic['id'], 'post.deleted', ['post_id' => $postId]);
        }
    }

    public function setLocked(?Context $ctx, int $topicId, bool $locked): void
    {
        $this->db->run('UPDATE topics SET locked = ? WHERE id = ?', [$locked, $topicId]);
        $this->hooks->doAction('topic.locked', $topicId, $locked, $ctx);
    }

    public function setPinned(?Context $ctx, int $topicId, bool $pinned): void
    {
        $this->db->run('UPDATE topics SET pinned = ? WHERE id = ?', [$pinned, $topicId]);
        $this->hooks->doAction('topic.pinned', $topicId, $pinned, $ctx);
    }

    public function move(?Context $ctx, int $topicId, int $nodeId): void
    {
        $topic = $this->topic($topicId) ?? throw new InvalidArgumentException('That topic no longer exists.');
        $node = $this->nodes->find($nodeId);
        if ($node === null || $node['type'] !== 'forum') {
            throw new InvalidArgumentException('Topics can only be moved into a forum.');
        }
        $this->db->transaction(function () use ($topic, $nodeId): void {
            $this->db->run('UPDATE topics SET node_id = ? WHERE id = ?', [$nodeId, $topic['id']]);
            $this->nodes->refreshStats((int) $topic['node_id']);
            $this->nodes->refreshStats($nodeId);
        });
        $this->hooks->doAction('topic.moved', $topicId, (int) $topic['node_id'], $nodeId, $ctx);
    }

    /**
     * Store a topic or post that arrived from another site. Rows are keyed by
     * their global ID, so receiving the same one twice does nothing.
     * @param array<string, mixed> $fields guid, topic_id, author_user_id, author_remote_id, body, created_at, edited_at, federation_state
     * @return array<string, mixed>|null the new row, or null when it already existed
     */
    public function insertImportedPost(array $fields): ?array
    {
        return $this->db->one(
            'INSERT INTO posts (guid, topic_id, author_user_id, author_remote_id, body, body_html, created_at, edited_at, federation_state)
             VALUES (?, ?, ?, ?, ?, ?, COALESCE(?::timestamptz, now()), ?::timestamptz, ?)
             ON CONFLICT (guid) DO NOTHING RETURNING *',
            [
                $fields['guid'], $fields['topic_id'], $fields['author_user_id'] ?? null, $fields['author_remote_id'] ?? null,
                $fields['body'], $this->markup->render((string) $fields['body']),
                $fields['created_at'] ?? null, $fields['edited_at'] ?? null, $fields['federation_state'] ?? 'remote',
            ],
        );
    }

    /** Update a post's text from another site without firing local edit hooks. */
    public function applyImportedEdit(int $postId, string $body, ?string $editedAt): void
    {
        $this->db->run(
            'UPDATE posts SET body = ?, body_html = ?, edited_at = COALESCE(?::timestamptz, now()) WHERE id = ?',
            [$body, $this->markup->render($body), $editedAt, $postId],
        );
    }

    /** Recalculate a topic's counters, first and last post after posts arrive or leave. */
    public function refreshTopic(int $topicId): void
    {
        $this->db->run(
            'UPDATE topics t SET
                post_count = (SELECT count(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL),
                first_post_id = (SELECT id FROM posts p WHERE p.topic_id = t.id ORDER BY p.created_at, p.id LIMIT 1),
                last_post_id = (SELECT id FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL ORDER BY p.created_at DESC, p.id DESC LIMIT 1),
                last_post_at = COALESCE((SELECT max(created_at) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL), t.created_at)
             WHERE t.id = ?',
            [$topicId],
        );
    }

    private function cleanBody(string $body, ?Context $ctx): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $body = (string) $this->hooks->applyFilters('post.body', $body, $ctx);
        if ($body === '') {
            throw new InvalidArgumentException('Write something before posting.');
        }
        if (mb_strlen($body) > 60000) {
            throw new InvalidArgumentException('Posts are limited to 60,000 characters.');
        }
        return $body;
    }

    /** @return array<string, mixed> */
    private function insertPost(Database $db, int $topicId, ?int $userId, string $body): array
    {
        $post = $db->one(
            'INSERT INTO posts (guid, topic_id, author_user_id, body, body_html) VALUES (?, ?, ?, ?, ?) RETURNING *',
            [Uuid::v7(), $topicId, $userId, $body, $this->markup->render($body)],
        );
        if ($userId !== null) {
            $db->run('UPDATE users SET post_count = post_count + 1 WHERE id = ?', [$userId]);
        }
        return $post;
    }
}
