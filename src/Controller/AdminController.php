<?php

declare(strict_types=1);

namespace AllianceBB\Controller;

use AllianceBB\Core\Context;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Response;
use AllianceBB\Plugin\PluginManager;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Users;
use AllianceBB\View\Themes;
use InvalidArgumentException;

final class AdminController extends Controller
{
    /** @param array<string, mixed> $data */
    private function adminPage(Context $ctx, string $template, string $section, array $data = []): Response
    {
        return $this->render($ctx, $template, $data + [
            'section' => $section,
            'admin_menu' => $this->app->hooks()->applyFilters('admin.menu', self::defaultMenu($this->app->router()), $ctx),
        ]);
    }

    /** @return list<array<string, string>> */
    public static function defaultMenu(\AllianceBB\Http\Router $router): array
    {
        return [
            ['id' => 'dashboard', 'title' => 'Dashboard', 'url' => $router->url('admin')],
            ['id' => 'structure', 'title' => 'Forums', 'url' => $router->url('admin.structure')],
            ['id' => 'sharing', 'title' => 'Sharing', 'url' => $router->url('admin.sharing')],
            ['id' => 'settings', 'title' => 'Settings', 'url' => $router->url('admin.settings')],
            ['id' => 'appearance', 'title' => 'Appearance', 'url' => $router->url('admin.appearance')],
            ['id' => 'plugins', 'title' => 'Plugins', 'url' => $router->url('admin.plugins')],
            ['id' => 'users', 'title' => 'Users', 'url' => $router->url('admin.users')],
            ['id' => 'updates', 'title' => 'Updates', 'url' => $router->url('admin.updates')],
        ];
    }

    public function dashboard(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $db = $this->app->db();
        return $this->adminPage($ctx, 'admin/dashboard.twig', 'dashboard', [
            'stats' => [
                'users' => (int) $db->value('SELECT count(*) FROM users'),
                'topics' => (int) $db->value('SELECT count(*) FROM topics WHERE deleted_at IS NULL'),
                'posts' => (int) $db->value('SELECT count(*) FROM posts WHERE deleted_at IS NULL'),
                'forums' => (int) $db->value("SELECT count(*) FROM nodes WHERE type = 'forum'"),
            ],
            'version' => \AllianceBB\App::VERSION,
            'php_version' => PHP_VERSION,
            'worker_mode' => function_exists('frankenphp_handle_request'),
        ]);
    }

    public function structure(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $nodes = $this->app->get(Nodes::class);
        if ($ctx->request->isPost()) {
            try {
                $parent = $ctx->request->input('parent_id');
                $nodes->create(
                    $ctx->request->input('type'),
                    $ctx->request->input('title'),
                    $ctx->request->input('description'),
                    $parent === '' ? null : (int) $parent,
                );
                $ctx->flash('Created.');
            } catch (InvalidArgumentException $e) {
                $ctx->flash($e->getMessage(), 'error');
            }
            return Response::redirect($this->url('admin.structure'));
        }
        return $this->adminPage($ctx, 'admin/structure.twig', 'structure', [
            'nodes' => $nodes->flatten($nodes->tree()),
        ]);
    }

    public function structureItem(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $nodes = $this->app->get(Nodes::class);
        $node = $nodes->find($ctx->request->intParam('id')) ?? throw new HttpException(404);
        try {
            if ($ctx->request->input('action') === 'delete') {
                $nodes->delete((int) $node['id']);
                $ctx->flash('Deleted "' . $node['title'] . '" and everything in it.');
            } else {
                $nodes->update(
                    (int) $node['id'],
                    $ctx->request->input('title'),
                    $ctx->request->input('description'),
                    (int) $ctx->request->input('position', (string) $node['position']),
                );
                $ctx->flash('Saved.');
            }
        } catch (InvalidArgumentException $e) {
            $ctx->flash($e->getMessage(), 'error');
        }
        return Response::redirect($this->url('admin.structure'));
    }

    public function settings(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $settings = $this->app->settings();
        if ($ctx->request->isPost()) {
            $name = $ctx->request->input('site_name');
            $mode = $ctx->request->input('federation_mode');
            if ($name === '' || mb_strlen($name) > 60) {
                $ctx->flash('Site names are 1 to 60 characters.', 'error');
            } elseif (!in_array($mode, ['public', 'private', 'off'], true)) {
                $ctx->flash('Choose public, private or off for sharing.', 'error');
            } else {
                $settings->set('site.name', $name);
                $settings->set('site.description', mb_substr($ctx->request->input('site_description'), 0, 300));
                $settings->set('site.registration_open', $ctx->request->input('registration_open') === '1');
                $settings->set('federation.mode', $mode);
                $url = rtrim($ctx->request->input('site_url'), '/');
                if ($url !== '' && preg_match('#^https?://[^/\s]+#i', $url)) {
                    $settings->set('site.url', $url);
                }
                $this->app->hooks()->doAction('admin.settings.saved', $ctx);
                $this->app->touchConfig();
                $ctx->flash('Settings saved.');
            }
            return Response::redirect($this->url('admin.settings'));
        }
        return $this->adminPage($ctx, 'admin/settings.twig', 'settings', ['settings' => $settings->all()]);
    }

    public function appearance(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $themes = $this->app->get(Themes::class);
        $settings = $this->app->settings();
        $active = (string) $settings->get('theme.name');
        if ($ctx->request->isPost()) {
            if ($ctx->request->input('action') === 'activate') {
                $theme = $ctx->request->input('theme');
                if ($themes->get($theme) === null) {
                    $ctx->flash('That theme is not installed.', 'error');
                } else {
                    $settings->set('theme.name', $theme);
                    $ctx->flash('Theme switched.');
                }
            } else {
                $all = (array) $settings->get('theme.colors');
                $colors = [];
                foreach (array_keys($themes->colors($active, [])) as $key) {
                    $value = (string) ($ctx->request->post['colors'][$key] ?? '');
                    if (Themes::validColor($value)) {
                        $colors[$key] = $value;
                    }
                }
                $all[$active] = $ctx->request->input('action') === 'reset' ? [] : $colors;
                $settings->set('theme.colors', $all);
                $ctx->flash($ctx->request->input('action') === 'reset' ? 'Colors reset to the theme defaults.' : 'Colors saved.');
            }
            $this->app->touchConfig();
            return Response::redirect($this->url('admin.appearance'));
        }
        return $this->adminPage($ctx, 'admin/appearance.twig', 'appearance', [
            'themes' => $themes->all(),
            'active' => $active,
            'colors' => $themes->colors($active, (array) $settings->get('theme.colors')),
        ]);
    }

    public function plugins(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $plugins = $this->app->get(PluginManager::class);
        if ($ctx->request->isPost()) {
            try {
                $name = $ctx->request->input('plugin');
                match ($ctx->request->input('action')) {
                    'enable' => $plugins->enable($name),
                    'disable' => $plugins->disable($name),
                    'upload' => $name = $plugins->installZip($this->uploadedFile($ctx, 'zip')),
                    default => throw new HttpException(400),
                };
                $this->app->touchConfig();
                $ctx->flash(match ($ctx->request->input('action')) {
                    'enable' => "Enabled $name.",
                    'disable' => "Disabled $name.",
                    default => "Uploaded $name. Enable it below when you're ready.",
                });
            } catch (InvalidArgumentException $e) {
                $ctx->flash($e->getMessage(), 'error');
            }
            return Response::redirect($this->url('admin.plugins'));
        }
        return $this->adminPage($ctx, 'admin/plugins.twig', 'plugins', ['plugins' => $plugins->all()]);
    }

    public function users(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $users = $this->app->get(Users::class);
        if ($ctx->request->isPost()) {
            $id = (int) $ctx->request->input('user_id');
            try {
                if ($id === $ctx->userId()) {
                    throw new InvalidArgumentException('You cannot change your own role or ban yourself.');
                }
                match ($ctx->request->input('action')) {
                    'role' => $users->setRole($id, $ctx->request->input('role')),
                    'ban' => $users->setBanned($id, true),
                    'unban' => $users->setBanned($id, false),
                    default => throw new HttpException(400),
                };
                $ctx->flash('User updated.');
            } catch (InvalidArgumentException $e) {
                $ctx->flash($e->getMessage(), 'error');
            }
            return Response::redirect($this->url('admin.users'));
        }
        return $this->adminPage($ctx, 'admin/users.twig', 'users', ['users' => $users->list(200)]);
    }

    public function updates(Context $ctx): Response
    {
        $this->requireAdmin($ctx);
        $updater = new \AllianceBB\Update\Updater($this->app);
        if ($ctx->request->isPost()) {
            @set_time_limit(600);
            try {
                switch ($ctx->request->input('action')) {
                    case 'github':
                        $version = $updater->installLatest();
                        $ctx->flash("Updated to AllianceBB $version.");
                        break;
                    case 'upload':
                        $file = $ctx->request->files['package'] ?? [];
                        $version = $updater->install($this->uploadedFile($ctx, 'package'), (string) ($file['name'] ?? ''));
                        $ctx->flash("Updated to AllianceBB $version.");
                        break;
                    case 'rollback':
                        $version = $updater->rollback($ctx->request->input('backup'));
                        $ctx->flash("Rolled back to AllianceBB $version.");
                        break;
                    default:
                        throw new HttpException(400);
                }
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $ctx->flash($e->getMessage(), 'error');
            }
            return Response::redirect($this->url('admin.updates'));
        }
        $latest = null;
        $checkError = null;
        if ($ctx->request->query('check') === '1') {
            try {
                $latest = $updater->latestRelease();
                $checkError = $latest === null ? 'No releases have been published on GitHub yet.' : null;
            } catch (\Throwable $e) {
                $checkError = $e->getMessage();
            }
        }
        return $this->adminPage($ctx, 'admin/updates.twig', 'updates', [
            'version' => \AllianceBB\App::VERSION,
            'latest' => $latest,
            'newer' => $latest !== null && version_compare($latest['version'], \AllianceBB\App::VERSION, '>'),
            'check_error' => $checkError,
            'backups' => $updater->backups(),
            'repo' => \AllianceBB\Update\Updater::REPO,
        ]);
    }

    private function uploadedFile(Context $ctx, string $field): string
    {
        $file = $ctx->request->files[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('Choose a file to upload.');
        }
        return (string) $file['tmp_name'];
    }
}
