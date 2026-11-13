<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\App;
use AllianceBB\Core\Context;
use AllianceBB\Federation\Federation;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Live\Broadcaster;
use AllianceBB\Live\DatabaseBroadcaster;
use AllianceBB\Service\ApiTokens;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Posts;
use AllianceBB\Service\Throttle;
use AllianceBB\Service\Users;

/**
 * The public API for apps, bots and other clients: JSON under /api/v1.
 * Reference: docs/api.md. Every resource passes through the "api.resource"
 * filter so plugins can add fields.
 */
final class ApiController extends Controller
{
    public const PREFIX = '/api/v1';
    public const VERSION = 1;

    /** @var array<int, array<string, mixed>>|null connection id => origin site, filled once per request */
    private ?array $origins = null;

    // ----- site, tokens, users ------------------------------------------------

    public function site(Context $ctx): Response
    {
        $settings = $this->app->settings();
        return $this->ok($ctx, [
            'name' => (string) $settings->get('site.name'),
            'description' => (string) $settings->get('site.description'),
            'url' => $this->baseUrl($ctx),
            'software' => 'AllianceBB',
            'version' => App::VERSION,
            'api_version' => self::VERSION,
            'registration_open' => (bool) $settings->get('site.registration_open'),
            'sharing' => (string) $settings->get('federation.mode'),
            'live' => $this->app->get(Broadcaster::class)->clientConfig(),
        ], 'site');
    }

    /** Sign in with a username or email and password, and get a token for an app. */
    public function createToken(Context $ctx): Response
    {
        $in = $this->input($ctx);
        $login = (string) ($in['login'] ?? '');
        $keys = Throttle::keys($ctx->request->ip, $login);
        $throttle = $this->app->get(Throttle::class);
        $throttle->check($keys);
        $user = $this->app->get(Users::class)->verify($login, (string) ($in['password'] ?? ''));
        if ($user === null) {
            $throttle->fail($keys);
            throw new HttpException(401, 'That username or password is not right.');
        }
        if ($user['banned_at'] !== null) {
            throw new HttpException(403, 'This account is banned.');
        }
        $throttle->clear($keys);
        $created = $this->app->get(ApiTokens::class)->create(
            (int) $user['id'],
            (string) ($in['name'] ?? 'App'),
            (string) ($in['scope'] ?? 'write'),
        );
        $ctx->user = $user;
        return $this->ok($ctx, [
            'token' => $created['token'],
            'token_info' => $this->tokenResource($created['row']),
            'user' => $this->userResource($ctx, $user, true),
        ], 'token', 201);
    }

    public function tokens(Context $ctx): Response
    {
        $this->requireToken($ctx);
        $rows = $this->app->get(ApiTokens::class)->forUser((int) $ctx->userId());
        return $this->ok($ctx, array_map(fn ($r) => $this->tokenResource($r, $ctx), $rows), 'tokens');
    }

    public function revokeToken(Context $ctx): Response
    {
        $this->requireToken($ctx);
        $id = $ctx->request->param('id') === 'current' ? (int) $ctx->apiToken['id'] : $ctx->request->intParam('id');
        if (!$this->app->get(ApiTokens::class)->revoke((int) $ctx->userId(), $id)) {
            throw new HttpException(404, 'No such token.');
        }
        return new Response('', 204, []);
    }

    public function me(Context $ctx): Response
    {
        $this->requireToken($ctx);
        return $this->ok($ctx, $this->userResource($ctx, $ctx->user, true), 'user');
    }

    public function user(Context $ctx): Response
    {
        $user = $this->app->get(Users::class)->findByLogin($ctx->request->param('username'));
        if ($user === null || str_contains($ctx->request->param('username'), '@')) {
            throw new HttpException(404, 'No such user.');
        }
        return $this->ok($ctx, $this->userResource($ctx, $user), 'user');
    }

    // ----- forums -------------------------------------------------------------

    public function forums(Context $ctx): Response
    {
        $tree = $this->app->get(Nodes::class)->tree();
        return $this->ok($ctx, array_map(fn ($n) => $this->forumResource($ctx, $n, true), $tree), 'forums');
    }

    public function forum(Context $ctx): Response
    {
        $nodes = $this->app->get(Nodes::class);
        $id = $ctx->request->intParam('id');
        $node = null;
        foreach ($nodes->flatten($nodes->tree()) as $n) {
            if ((int) $n['id'] === $id) {
                $node = $n;
                break;
            }
        }
        $node ?? throw new HttpException(404, 'No such forum.');
        $data = $this->forumResource($ctx, $node, true);
        $data['parents'] = array_map(fn ($b) => [
            'id' => (int) $b['id'], 'type' => $b['type'], 'title' => $b['title'],
        ], $nodes->breadcrumbs($id));
        return $this->ok($ctx, $data, 'forum');
    }

    public function topics(Context $ctx): Response
    {
        $node = $this->app->get(Nodes::class)->find($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such forum.');
        $page = $this->pageNumber($ctx);
        $rows = $this->posts()->topicsIn((int) $node['id'], $page);
        return $this->ok($ctx, array_map(fn ($t) => $this->topicResource($ctx, $t), $rows), 'topics', 200, [
            'page' => $page,
            'per_page' => Posts::TOPICS_PER_PAGE,
            'total' => (int) $node['topic_count'],
            'pages' => max(1, (int) ceil((int) $node['topic_count'] / Posts::TOPICS_PER_PAGE)),
        ]);
    }

    public function createTopic(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        $node = $this->app->get(Nodes::class)->find($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such forum.');
        $this->authorize($ctx, 'topic.create', $node);
        $in = $this->input($ctx);
        $result = $this->posts()->createTopic($ctx, (int) $node['id'], (string) ($in['title'] ?? ''), (string) ($in['body'] ?? ''));
        $topic = $this->posts()->topicRow((int) $result['topic']['id']) ?? $result['topic'];
        $post = $this->posts()->postAnyState((int) $result['post']['id']) ?? $result['post'];
        return $this->ok($ctx, [
            'topic' => $this->topicResource($ctx, $topic),
            'post' => $this->postResource($ctx, $post, $topic),
        ], 'topic.created', 201);
    }

    // ----- topics -------------------------------------------------------------

    public function topic(Context $ctx): Response
    {
        return $this->ok($ctx, $this->topicResource($ctx, $this->findTopic($ctx)), 'topic');
    }

    public function updateTopic(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        $topic = $this->findTopic($ctx);
        $this->authorize($ctx, 'topic.moderate', $topic);
        $in = $this->input($ctx);
        $id = (int) $topic['id'];
        if (array_key_exists('locked', $in)) {
            $this->posts()->setLocked($ctx, $id, (bool) $in['locked']);
        }
        if (array_key_exists('pinned', $in)) {
            $this->posts()->setPinned($ctx, $id, (bool) $in['pinned']);
        }
        if (array_key_exists('forum_id', $in) && (int) $in['forum_id'] !== (int) $topic['node_id']) {
            $this->posts()->move($ctx, $id, (int) $in['forum_id']);
        }
        return $this->ok($ctx, $this->topicResource($ctx, $this->findTopic($ctx)), 'topic');
    }

    /** ?page=N for pages of 25, or ?after=<post id> for everything newer (up to ?limit=, max 100). */
    public function topicPosts(Context $ctx): Response
    {
        $topic = $this->findTopic($ctx);
        $after = $ctx->request->query('after');
        if ($after !== '') {
            $limit = min(100, max(1, (int) $ctx->request->query('limit', '50')));
            $rows = $this->posts()->postsAfter((int) $topic['id'], max(0, (int) $after), $limit);
            $meta = ['after' => (int) $after, 'limit' => $limit, 'more' => count($rows) === $limit];
        } else {
            $page = $this->pageNumber($ctx);
            $rows = $this->posts()->postsIn((int) $topic['id'], $page);
            $meta = [
                'page' => $page,
                'per_page' => Posts::POSTS_PER_PAGE,
                'total' => (int) $topic['post_count'],
                'pages' => max(1, (int) ceil((int) $topic['post_count'] / Posts::POSTS_PER_PAGE)),
            ];
        }
        return $this->ok($ctx, array_map(fn ($p) => $this->postResource($ctx, $p, $topic), $rows), 'posts', 200, $meta);
    }

    public function reply(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        $topic = $this->findTopic($ctx);
        $this->authorize($ctx, 'post.reply', $topic);
        $post = $this->posts()->reply($ctx, (int) $topic['id'], (string) ($this->input($ctx)['body'] ?? ''));
        $post = $this->posts()->postAnyState((int) $post['id']) ?? $post;
        return $this->ok($ctx, $this->postResource($ctx, $post, $topic), 'post', 201);
    }

    // ----- posts --------------------------------------------------------------

    public function post(Context $ctx): Response
    {
        [$post, $topic] = $this->findPost($ctx);
        return $this->ok($ctx, $this->postResource($ctx, $post, $topic), 'post');
    }

    public function editPost(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        [$post, $topic] = $this->findPost($ctx);
        $this->authorize($ctx, 'post.edit', $post);
        $this->posts()->edit($ctx, (int) $post['id'], (string) ($this->input($ctx)['body'] ?? ''));
        $post = $this->posts()->postAnyState((int) $post['id']) ?? $post;
        return $this->ok($ctx, $this->postResource($ctx, $post, $topic), 'post');
    }

    public function deletePost(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        [$post] = $this->findPost($ctx);
        $this->authorize($ctx, 'post.delete', $post);
        $this->posts()->delete($ctx, (int) $post['id']);
        return new Response('', 204, []);
    }

    public function hidePost(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        $fed = $this->app->get(Federation::class);
        if ($ctx->request->method === 'DELETE') {
            $post = $this->posts()->postAnyState($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such post.');
            $this->authorize($ctx, 'post.hide', $post);
            $fed->unhidePost((int) $post['id']);
        } else {
            [$post] = $this->findPost($ctx);
            $this->authorize($ctx, 'post.hide', $post);
            $fed->hidePost((int) $post['id'], $ctx->userId());
        }
        return new Response('', 204, []);
    }

    public function blockAuthor(Context $ctx): Response
    {
        $this->requireWrite($ctx);
        $post = $this->posts()->postAnyState($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such post.');
        $this->authorize($ctx, 'post.block', $post);
        if ($post['author_remote_id'] === null) {
            throw new HttpException(422, 'Only authors from other sites can be blocked.');
        }
        $this->app->get(Federation::class)->blockRemoteUser((int) $post['author_remote_id'], $ctx->userId(), $ctx->request->method !== 'DELETE');
        return new Response('', 204, []);
    }

    // ----- live updates -------------------------------------------------------

    /** Polling for changes when the site has no Mercure hub. See "live" in GET /api/v1/site. */
    public function live(Context $ctx): Response
    {
        $live = $this->app->get(Broadcaster::class);
        if (!$live instanceof DatabaseBroadcaster) {
            throw new HttpException(404, 'This site pushes live updates through Mercure. See "live" in GET /api/v1/site.');
        }
        $channels = array_values(array_filter(
            explode(',', $ctx->request->query('channels')),
            fn (string $c) => (bool) preg_match('#^(topic|node)/\d+$#', $c),
        ));
        if ($channels === []) {
            throw new HttpException(422, 'Pass ?channels=topic/12,node/3 (up to 20).');
        }
        $after = max(0, (int) $ctx->request->query('after', '0'));
        $events = $live->since(array_slice($channels, 0, 20), $after);
        $last = $events === [] ? $after : (int) end($events)['id'];
        return Response::json(['data' => $events, 'meta' => ['after' => max($last, $after)]])
            ->withHeader('cache-control', 'no-store');
    }

    // ----- resources ----------------------------------------------------------

    /** @param array<string, mixed> $user */
    private function userResource(Context $ctx, array $user, bool $self = false): array
    {
        $data = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'] ?: $user['username'],
            'avatar_url' => $user['avatar_url'] ?: null,
            'role' => $user['role'],
            'post_count' => (int) $user['post_count'],
            'created_at' => $this->time($user['created_at']),
        ];
        if ($self) {
            $data['email'] = $user['email'];
            $data['token_scope'] = $ctx->apiToken['scope'] ?? null;
        }
        return $this->filter($ctx, $data, 'user', $user);
    }

    /** @param array<string, mixed> $row */
    private function tokenResource(array $row, ?Context $ctx = null): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'prefix' => $row['prefix'],
            'scope' => $row['scope'],
            'current' => $ctx !== null && (int) $row['id'] === (int) ($ctx->apiToken['id'] ?? 0),
            'last_used_at' => $this->time($row['last_used_at']),
            'created_at' => $this->time($row['created_at']),
        ];
    }

    /** @param array<string, mixed> $node */
    private function forumResource(Context $ctx, array $node, bool $withChildren): array
    {
        $origin = $node['origin_connection_id'] !== null ? ($this->origins()[(int) $node['origin_connection_id']] ?? null) : null;
        $data = [
            'id' => (int) $node['id'],
            'guid' => $node['guid'],
            'type' => $node['type'],
            'parent_id' => $node['parent_id'] === null ? null : (int) $node['parent_id'],
            'title' => $node['title'],
            'description' => $node['description'],
            'position' => (int) $node['position'],
            'topic_count' => (int) $node['topic_count'],
            'post_count' => (int) $node['post_count'],
            'last_post' => isset($node['last_topic_id']) && $node['last_topic_id'] !== null ? [
                'post_id' => (int) $node['last_post_id'],
                'topic_id' => (int) $node['last_topic_id'],
                'topic_title' => $node['last_topic_title'],
                'username' => $node['last_post_username'],
                'created_at' => $this->time($node['last_post_at']),
            ] : null,
            'cloned_from' => $origin,
            'can' => ['create_topic' => $node['type'] === 'forum' && $this->can($ctx, 'topic.create', $node)],
            'url' => $this->absolute($ctx, 'forum', ['id' => $node['id'] . '-' . $node['slug']]),
        ];
        if ($withChildren) {
            $data['children'] = array_map(fn ($c) => $this->forumResource($ctx, $c, true), $node['children'] ?? []);
        }
        return $this->filter($ctx, $data, 'forum', $node);
    }

    /** @param array<string, mixed> $topic a row from Posts::topicRow() or topicsIn() */
    private function topicResource(Context $ctx, array $topic): array
    {
        $data = [
            'id' => (int) $topic['id'],
            'guid' => $topic['guid'],
            'forum_id' => (int) $topic['node_id'],
            'title' => $topic['title'],
            'author' => $topic['author_username'] ?? null,
            'post_count' => (int) $topic['post_count'],
            'locked' => (bool) $topic['locked'],
            'pinned' => (bool) $topic['pinned'],
            'first_post_id' => $topic['first_post_id'] === null ? null : (int) $topic['first_post_id'],
            'last_post_id' => $topic['last_post_id'] === null ? null : (int) $topic['last_post_id'],
            'last_post_by' => $topic['last_username'] ?? null,
            'last_post_at' => $this->time($topic['last_post_at']),
            'created_at' => $this->time($topic['created_at']),
            'can' => [
                'reply' => $this->can($ctx, 'post.reply', $topic),
                'moderate' => $this->can($ctx, 'topic.moderate', $topic),
            ],
            'url' => $this->absolute($ctx, 'topic', ['id' => $topic['id'] . '-' . $topic['slug']]),
        ];
        return $this->filter($ctx, $data, 'topic', $topic);
    }

    /** @param array<string, mixed> $post a row from Posts::post() and friends */
    private function postResource(Context $ctx, array $post, array $topic): array
    {
        $data = [
            'id' => (int) $post['id'],
            'guid' => $post['guid'],
            'topic_id' => (int) $post['topic_id'],
            'author' => [
                'username' => $post['username'],
                'display_name' => $post['display_name'] ?: $post['username'],
                'handle' => $post['author_handle'] ?? $post['username'],
                'avatar_url' => $post['avatar_url'] ?: null,
                'role' => $post['author_role'],
                'remote' => (bool) $post['is_remote'],
            ],
            'body' => $post['body'],
            'body_html' => $post['body_html'],
            'first_post' => (int) $topic['first_post_id'] === (int) $post['id'],
            'state' => $post['federation_state'],
            'error' => $post['federation_error'],
            'created_at' => $this->time($post['created_at']),
            'edited_at' => $this->time($post['edited_at']),
            'can' => [
                'edit' => $this->can($ctx, 'post.edit', $post),
                'delete' => $this->can($ctx, 'post.delete', $post),
                'hide' => $this->can($ctx, 'post.hide', $post),
                'block_author' => (bool) $post['is_remote'] && $this->can($ctx, 'post.block', $post),
            ],
            'url' => $this->absolute($ctx, 'post', ['id' => $post['id']]),
        ];
        return $this->filter($ctx, $data, 'post', $post);
    }

    // ----- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed> $meta
     */
    private function ok(Context $ctx, array $data, string $type, int $status = 200, array $meta = []): Response
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        return Response::json($body, $status)->withHeader('cache-control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function filter(Context $ctx, array $data, string $type, array $row): array
    {
        return (array) $this->app->hooks()->applyFilters('api.resource', $data, $type, $row, $ctx);
    }

    /** JSON body, or form fields for simple clients. @return array<string, mixed> */
    private function input(Context $ctx): array
    {
        $type = $ctx->request->header('content-type');
        if (str_contains($type, 'json')) {
            $data = json_decode($ctx->request->body, true);
            if (!is_array($data)) {
                throw new HttpException(400, 'The request body is not valid JSON.');
            }
            return $data;
        }
        return $ctx->request->post;
    }

    private function requireToken(Context $ctx): void
    {
        if ($ctx->apiToken === null) {
            throw new HttpException(401, 'Send a token: "Authorization: Bearer abbt_…".');
        }
    }

    private function requireWrite(Context $ctx): void
    {
        $this->requireToken($ctx);
        if ($ctx->apiToken['scope'] !== 'write') {
            throw new HttpException(403, 'This token is read-only.');
        }
    }

    /** @return array<string, mixed> */
    private function findTopic(Context $ctx): array
    {
        return $this->posts()->topicRow($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such topic.');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function findPost(Context $ctx): array
    {
        $post = $this->posts()->post($ctx->request->intParam('id')) ?? throw new HttpException(404, 'No such post.');
        $topic = $this->posts()->topicRow((int) $post['topic_id']) ?? throw new HttpException(404, 'No such post.');
        return [$post, $topic];
    }

    private function posts(): Posts
    {
        return $this->app->get(Posts::class);
    }

    /** @return array<int, array<string, mixed>> */
    private function origins(): array
    {
        if ($this->origins === null) {
            $this->origins = [];
            foreach ($this->app->db()->all(
                'SELECT c.id, rs.name, rs.handle, rs.base_url FROM connections c JOIN remote_sites rs ON rs.id = c.remote_site_id',
            ) as $row) {
                $this->origins[(int) $row['id']] = ['site' => $row['name'], 'handle' => $row['handle'], 'url' => $row['base_url']];
            }
        }
        return $this->origins;
    }

    private function baseUrl(Context $ctx): string
    {
        $url = (string) $this->app->settings()->get('site.url');
        return rtrim($url !== '' ? $url : $ctx->request->baseUrl(), '/');
    }

    /** @param array<string, mixed> $params */
    private function absolute(Context $ctx, string $route, array $params): string
    {
        return $this->baseUrl($ctx) . $this->url($route, $params);
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : date(DATE_ATOM, (int) strtotime((string) $value));
    }

    /** Turns any error on an API path into a JSON error body. */
    public static function errorResponse(int $status, string $message): Response
    {
        $code = match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'invalid',
            429 => 'rate_limited',
            default => 'server_error',
        };
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function isApiPath(string $path): bool
    {
        return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');
    }

    public static function cors(Response $response): Response
    {
        return $response
            ->withHeader('access-control-allow-origin', '*')
            ->withHeader('access-control-allow-methods', 'GET, POST, PATCH, DELETE, OPTIONS')
            ->withHeader('access-control-allow-headers', 'Authorization, Content-Type')
            ->withHeader('access-control-max-age', '86400');
    }
}
