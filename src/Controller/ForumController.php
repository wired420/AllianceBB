<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Posts;
use InvalidArgumentException;

final class ForumController extends Controller
{
    private function nodes(): Nodes
    {
        return $this->app->get(Nodes::class);
    }

    private function posts(): Posts
    {
        return $this->app->get(Posts::class);
    }

    public function index(Context $ctx): Response
    {
        return $this->render($ctx, 'index.twig', ['tree' => $this->nodes()->tree()]);
    }

    public function forum(Context $ctx): Response
    {
        $node = $this->nodes()->find($ctx->request->intParam('id')) ?? throw new HttpException(404);
        if ($node['type'] === 'category') {
            return $this->render($ctx, 'index.twig', [
                'tree' => array_values(array_filter($this->nodes()->tree(), fn ($n) => (int) $n['id'] === (int) $node['id'])),
                'category' => $node,
            ]);
        }
        $page = $this->page($ctx);
        return $this->render($ctx, 'forum.twig', [
            'node' => $node,
            'breadcrumbs' => $this->nodes()->breadcrumbs((int) $node['id']),
            'subforums' => $this->nodes()->children((int) $node['id']),
            'topics' => $this->posts()->topicsIn((int) $node['id'], $page),
            'page' => $page,
            'pages' => max(1, (int) ceil((int) $node['topic_count'] / Posts::TOPICS_PER_PAGE)),
        ]);
    }

    public function newTopic(Context $ctx): Response
    {
        $node = $this->nodes()->find($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'topic.create', $node);
        $error = null;
        if ($ctx->request->isPost()) {
            try {
                $result = $this->posts()->createTopic($ctx, (int) $node['id'], $ctx->request->input('title'), $ctx->request->input('body'));
                return Response::redirect($this->url('topic', ['id' => $result['topic']['id'] . '-' . $result['topic']['slug']]));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render($ctx, 'topic_new.twig', [
            'node' => $node,
            'breadcrumbs' => [...$this->nodes()->breadcrumbs((int) $node['id']), $node],
            'error' => $error,
            'title' => $ctx->request->input('title'),
            'body' => $ctx->request->input('body'),
        ]);
    }

    public function topic(Context $ctx): Response
    {
        $topic = $this->posts()->topic($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $node = $this->nodes()->find((int) $topic['node_id']) ?? throw new HttpException(404);
        $page = $this->page($ctx);
        return $this->render($ctx, 'topic.twig', [
            'topic' => $topic,
            'node' => $node,
            'breadcrumbs' => [...$this->nodes()->breadcrumbs((int) $node['id']), $node],
            'posts' => $this->posts()->postsIn((int) $topic['id'], $page),
            'page' => $page,
            'pages' => max(1, (int) ceil((int) $topic['post_count'] / Posts::POSTS_PER_PAGE)),
            'forums' => $ctx->isModerator()
                ? array_filter($this->nodes()->flatten($this->nodes()->tree()), fn ($n) => $n['type'] === 'forum')
                : [],
        ]);
    }

    public function reply(Context $ctx): Response
    {
        $topic = $this->posts()->topic($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'post.reply', $topic);
        try {
            $post = $this->posts()->reply($ctx, (int) $topic['id'], $ctx->request->input('body'));
        } catch (InvalidArgumentException $e) {
            if ($ctx->request->wantsJson()) {
                return Response::json(['error' => $e->getMessage()], 422);
            }
            $ctx->flash($e->getMessage(), 'error');
            return Response::redirect($this->url('topic', ['id' => $topic['id']]) . '#reply');
        }
        if ($ctx->request->wantsJson()) {
            return Response::json(['post_id' => (int) $post['id']]);
        }
        return Response::redirect($this->url('post', ['id' => $post['id']]));
    }

    /** Redirect to the page and anchor of a post. */
    public function post(Context $ctx): Response
    {
        $post = $this->posts()->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $page = $this->posts()->pageOfPost((int) $post['topic_id'], (int) $post['id']);
        $url = $this->url('topic', ['id' => $post['topic_id']]) . ($page > 1 ? '?page=' . $page : '') . '#post-' . $post['id'];
        return Response::redirect($url, 302);
    }

    /** One post rendered for this viewer; used by live updates. */
    public function postFragment(Context $ctx): Response
    {
        $post = $this->posts()->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $topic = $this->posts()->topic((int) $post['topic_id']) ?? throw new HttpException(404);
        return $this->render($ctx, 'partials/post.twig', ['post' => $post, 'topic' => $topic]);
    }

    /** One topic row rendered for a forum page; used by live updates. */
    public function topicRow(Context $ctx): Response
    {
        $topic = $this->posts()->topicRow($ctx->request->intParam('id')) ?? throw new HttpException(404);
        return $this->render($ctx, 'partials/topic_row.twig', ['topic' => $topic]);
    }

    public function edit(Context $ctx): Response
    {
        $post = $this->posts()->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'post.edit', $post);
        $topic = $this->posts()->topic((int) $post['topic_id']) ?? throw new HttpException(404);
        $error = null;
        if ($ctx->request->isPost()) {
            try {
                $this->posts()->edit($ctx, (int) $post['id'], $ctx->request->input('body'));
                return Response::redirect($this->url('post', ['id' => $post['id']]));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render($ctx, 'post_edit.twig', [
            'post' => $post,
            'topic' => $topic,
            'error' => $error,
            'body' => $ctx->request->isPost() ? $ctx->request->input('body') : $post['body'],
        ]);
    }

    public function delete(Context $ctx): Response
    {
        $post = $this->posts()->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'post.delete', $post);
        $topic = $this->posts()->topic((int) $post['topic_id']) ?? throw new HttpException(404);
        $this->posts()->delete($ctx, (int) $post['id']);
        if ((int) $topic['first_post_id'] === (int) $post['id']) {
            $ctx->flash('Topic deleted.');
            return Response::redirect($this->url('forum', ['id' => $topic['node_id']]));
        }
        $ctx->flash('Post deleted.');
        return Response::redirect($this->url('topic', ['id' => $topic['id']]));
    }

    public function moderate(Context $ctx): Response
    {
        $topic = $this->posts()->topic($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'topic.moderate', $topic);
        $id = (int) $topic['id'];
        try {
            match ($ctx->request->input('action')) {
                'lock' => $this->posts()->setLocked($ctx, $id, true),
                'unlock' => $this->posts()->setLocked($ctx, $id, false),
                'pin' => $this->posts()->setPinned($ctx, $id, true),
                'unpin' => $this->posts()->setPinned($ctx, $id, false),
                'move' => $this->posts()->move($ctx, $id, (int) $ctx->request->input('node_id')),
                default => throw new HttpException(400),
            };
            $ctx->flash('Topic updated.');
        } catch (InvalidArgumentException $e) {
            $ctx->flash($e->getMessage(), 'error');
        }
        return Response::redirect($this->url('topic', ['id' => $id]));
    }
}
