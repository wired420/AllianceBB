<?php

declare(strict_types=1);

namespace AllianceBB\Service;

use AllianceBB\Core\Database;
use AllianceBB\Core\Hooks;
use AllianceBB\Core\Slug;
use InvalidArgumentException;

/** Categories, forums and sub-forums, kept as one tree. */
final class Nodes
{
    public function __construct(private readonly Database $db, private readonly Hooks $hooks)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM nodes WHERE id = ?', [$id]);
    }

    /**
     * Every node with its last post, nested: each node gets a "children" list.
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $rows = $this->db->all(
            'SELECT n.*, p.created_at AS last_post_at, t.id AS last_topic_id, t.title AS last_topic_title,
                    t.slug AS last_topic_slug, u.username AS last_post_username
               FROM nodes n
               LEFT JOIN posts p ON p.id = n.last_post_id
               LEFT JOIN topics t ON t.id = p.topic_id
               LEFT JOIN users u ON u.id = p.author_user_id
              ORDER BY n.position, n.id',
        );
        return $this->nest($rows, null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function nest(array $rows, ?int $parentId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ($rowParent === $parentId) {
                $row['children'] = $this->nest($rows, (int) $row['id']);
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the node's children with their last post */
    public function children(int $id): array
    {
        foreach ($this->flatten($this->tree()) as $node) {
            if ((int) $node['id'] === $id) {
                return $node['children'];
            }
        }
        return [];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public function flatten(array $nodes, int $depth = 0): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $node['depth'] = $depth;
            $out[] = $node;
            $out = array_merge($out, $this->flatten($node['children'] ?? [], $depth + 1));
        }
        return $out;
    }

    /** @return list<array<string, mixed>> ancestors from the root down, not including the node */
    public function breadcrumbs(int $id): array
    {
        return $this->db->all(
            'WITH RECURSIVE up AS (
                SELECT id, parent_id, title, slug, type, 0 AS depth FROM nodes WHERE id = (SELECT parent_id FROM nodes WHERE id = ?)
                UNION ALL
                SELECT n.id, n.parent_id, n.title, n.slug, n.type, up.depth + 1 FROM nodes n JOIN up ON n.id = up.parent_id
             ) SELECT * FROM up ORDER BY depth DESC',
            [$id],
        );
    }

    /** @return array<string, mixed> */
    public function create(string $type, string $title, string $description = '', ?int $parentId = null): array
    {
        $title = trim($title);
        if (!in_array($type, ['category', 'forum'], true)) {
            throw new InvalidArgumentException('Choose category or forum.');
        }
        if ($title === '' || mb_strlen($title) > 120) {
            throw new InvalidArgumentException('Titles are 1 to 120 characters.');
        }
        // Forums may sit at the top level, inside a category, or inside another forum (a sub-forum).
        if ($parentId !== null) {
            if ($this->find($parentId) === null) {
                throw new InvalidArgumentException('The parent does not exist.');
            }
            if ($type === 'category') {
                throw new InvalidArgumentException('Categories sit at the top level.');
            }
        }
        $position = (int) $this->db->value(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM nodes WHERE parent_id IS NOT DISTINCT FROM ?',
            [$parentId],
        );
        $node = $this->db->one(
            'INSERT INTO nodes (parent_id, type, title, slug, description, position) VALUES (?, ?, ?, ?, ?, ?) RETURNING *',
            [$parentId, $type, $title, Slug::make($title), trim($description), $position],
        );
        $this->hooks->doAction('node.created', $node);
        return $node;
    }

    public function update(int $id, string $title, string $description, int $position): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 120) {
            throw new InvalidArgumentException('Titles are 1 to 120 characters.');
        }
        $this->db->run(
            'UPDATE nodes SET title = ?, slug = ?, description = ?, position = ? WHERE id = ?',
            [$title, Slug::make($title), trim($description), $position, $id],
        );
        $this->hooks->doAction('node.updated', $this->find($id));
    }

    public function delete(int $id): void
    {
        $node = $this->find($id);
        $this->db->run('DELETE FROM nodes WHERE id = ?', [$id]);
        $this->hooks->doAction('node.deleted', $node);
    }

    /** Recalculate counters and the last post for a forum. */
    public function refreshStats(int $id): void
    {
        $this->db->run(
            'UPDATE nodes n SET
                topic_count = (SELECT count(*) FROM topics t WHERE t.node_id = n.id AND t.deleted_at IS NULL),
                post_count = (SELECT count(*) FROM posts p JOIN topics t ON t.id = p.topic_id
                               WHERE t.node_id = n.id AND t.deleted_at IS NULL AND p.deleted_at IS NULL),
                last_post_id = (SELECT p.id FROM posts p JOIN topics t ON t.id = p.topic_id
                                 WHERE t.node_id = n.id AND t.deleted_at IS NULL AND p.deleted_at IS NULL
                                 ORDER BY p.id DESC LIMIT 1)
             WHERE n.id = ?',
            [$id],
        );
    }
}
