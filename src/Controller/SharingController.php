<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Federation\Federation;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Posts;
use Throwable;

/** Admin › Sharing, plus the hide and block buttons on posts. */
final class SharingController extends Controller
{
    private function fed(): Federation
    {
        return $this->app->get(Federation::class);
    }

    public function page(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $fed = $this->fed();
        $nodes = $this->app->get(Nodes::class);

        if ($ctx->request->isPost()) {
            return $this->handle($ctx);
        }

        // A connection code is shown after the admin asks for one.
        $code = null;
        $codeFor = (int) $ctx->request->query('code_conn', '0');
        $codeNode = (int) $ctx->request->query('code_node', '0');
        if ($codeFor > 0 && $codeNode > 0) {
            try {
                $code = $fed->connectionCode($codeFor, $codeNode);
            } catch (\InvalidArgumentException $e) {
                $ctx->flash[] = ['type' => 'error', 'message' => $e->getMessage()];
            }
        }

        $discovered = null;
        $discoverUrl = $ctx->request->query('discover');
        if ($discoverUrl !== '') {
            try {
                $discovered = $fed->discover($discoverUrl) + ['query_url' => $discoverUrl];
            } catch (Throwable $e) {
                $ctx->flash[] = ['type' => 'error', 'message' => $e->getMessage()];
            }
        }

        $local = $fed->localSections();
        return $this->render($ctx, 'admin/sharing.twig', [
            'section' => 'sharing',
            'admin_menu' => $this->adminMenu($ctx),
            'mode' => $fed->mode(),
            'self_url' => $fed->selfUrl(),
            'webhook_url' => $fed->webhookUrl(),
            'subscribers' => $fed->connections('subscriber'),
            'clones' => $fed->clonedRoots(),
            'local_sections' => $local,
            'shareable' => array_values(array_filter($local, fn ($n) => $n['shareable'])),
            'placement' => array_filter($nodes->flatten($nodes->tree()), fn ($n) => $n['origin_connection_id'] === null),
            'code' => $code,
            'code_conn' => $codeFor,
            'code_node' => $codeNode,
            'discovered' => $discovered,
            'hidden_posts' => $fed->hiddenPosts(),
            'blocked_users' => $fed->blockedUsers(),
            'failed_jobs' => $fed->failedJobs(),
        ]);
    }

    private function handle(Context $ctx): Response
    {
        $fed = $this->fed();
        $back = $this->url('admin.sharing');
        $parent = $ctx->request->input('parent_id');
        $parentId = $parent === '' ? null : (int) $parent;
        try {
            switch ($ctx->request->input('action')) {
                case 'add_site':
                    $conn = $fed->issueConnection(
                        $ctx->request->input('name'),
                        $ctx->request->input('base_url'),
                        $ctx->request->input('webhook_url'),
                    );
                    $ctx->flash('Added ' . $ctx->request->input('name') . '. Now pick a section below and copy its connection code.');
                    return Response::redirect($back . '?code_conn=' . $conn['id'] . '#codes');
                case 'webhook':
                    $fed->setWebhook((int) $ctx->request->input('connection_id'), $ctx->request->input('webhook_url'));
                    $ctx->flash('Webhook address saved.');
                    break;
                case 'revoke':
                    $fed->revoke((int) $ctx->request->input('connection_id'));
                    $ctx->flash('Connection revoked. That site no longer receives or sends posts.');
                    break;
                case 'code':
                    return Response::redirect($back . '?code_conn=' . (int) $ctx->request->input('connection_id')
                        . '&code_node=' . (int) $ctx->request->input('node_id') . '#codes');
                case 'shareable':
                    $fed->setShareable((int) $ctx->request->input('node_id'), $ctx->request->input('shareable') === '1');
                    $ctx->flash('Sharing updated.');
                    break;
                case 'clone_code':
                    $id = $fed->cloneFromCode($ctx->request->input('code'), $parentId);
                    $ctx->flash('Section cloned. New posts will arrive automatically.');
                    return Response::redirect($this->url('forum', ['id' => $id]));
                case 'discover':
                    return Response::redirect($back . '?discover=' . rawurlencode($ctx->request->input('site_url')) . '#clone');
                case 'clone_url':
                    $id = $fed->cloneFromUrl($ctx->request->input('site_url'), $ctx->request->input('node_guid'), $parentId);
                    $ctx->flash('Section cloned. New posts will arrive automatically.');
                    return Response::redirect($this->url('forum', ['id' => $id]));
                case 'sync':
                    $n = $fed->sync((int) $ctx->request->input('node_id'));
                    $ctx->flash($n === 0 ? 'Already up to date.' : "Fetched $n updates.");
                    break;
                case 'disconnect':
                    $fed->disconnect((int) $ctx->request->input('node_id'));
                    $ctx->flash('Disconnected and removed the local copy.');
                    break;
                case 'unhide':
                    $fed->unhidePost((int) $ctx->request->input('post_id'));
                    $ctx->flash('Post shown again.');
                    break;
                case 'unblock':
                    $fed->blockRemoteUser((int) $ctx->request->input('remote_user_id'), $ctx->userId(), false);
                    $ctx->flash('User unblocked.');
                    break;
                default:
                    throw new HttpException(400);
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            $ctx->flash($e->getMessage(), 'error');
        }
        return Response::redirect($back);
    }

    /** Moderators: hide one post on this site only. */
    public function hide(Context $ctx): Response
    {
        $post = $this->app->get(Posts::class)->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'post.hide', $post);
        $this->fed()->hidePost((int) $post['id'], $ctx->userId());
        $ctx->flash('Post hidden on this site. Admins can show it again under Admin › Sharing.');
        return Response::redirect($this->url('topic', ['id' => $post['topic_id']]));
    }

    /** Moderators: hide everything a remote user has posted or will post, on this site only. */
    public function block(Context $ctx): Response
    {
        $post = $this->app->get(Posts::class)->post($ctx->request->intParam('id')) ?? throw new HttpException(404);
        $this->authorize($ctx, 'post.block', $post);
        $this->fed()->blockRemoteUser((int) $post['author_remote_id'], $ctx->userId());
        $ctx->flash('Blocked ' . $post['author_handle'] . '. Their posts no longer show on this site.');
        return Response::redirect($this->url('topic', ['id' => $post['topic_id']]));
    }

    /** @return list<array<string, string>> */
    private function adminMenu(Context $ctx): array
    {
        return $this->app->hooks()->applyFilters('admin.menu', AdminController::defaultMenu($this->app->router()), $ctx);
    }
}
