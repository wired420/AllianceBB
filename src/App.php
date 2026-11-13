<?php

declare(strict_types=1);

namespace AllianceBB;

use AllianceBB\Controller\AdminController;
use AllianceBB\Controller\AuthController;
use AllianceBB\Controller\ForumController;
use AllianceBB\Controller\InstallController;
use AllianceBB\Controller\SystemController;
use AllianceBB\Core\Container;
use AllianceBB\Core\Context;
use AllianceBB\Core\Database;
use AllianceBB\Core\Hooks;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Request;
use AllianceBB\Http\Response;
use AllianceBB\Http\Router;
use AllianceBB\Live\Broadcaster;
use AllianceBB\Live\DatabaseBroadcaster;
use AllianceBB\Live\MercureBroadcaster;
use AllianceBB\Plugin\PluginManager;
use AllianceBB\Service\Auth;
use AllianceBB\Service\Markup;
use AllianceBB\Service\Nodes;
use AllianceBB\Service\Permissions;
use AllianceBB\Service\Posts;
use AllianceBB\Service\Settings;
use AllianceBB\Service\Users;
use AllianceBB\View\Themes;
use AllianceBB\View\View;
use Throwable;

/**
 * The application. Booted once per worker (FrankenPHP worker mode) or once
 * per request (classic PHP); handle() must keep no per-request state.
 */
final class App
{
    public const VERSION = '0.1.0';

    private readonly Container $container;
    private readonly Hooks $hooks;
    private readonly Router $router;
    private int $configStamp;

    public function __construct(public readonly string $rootDir)
    {
        $this->container = new Container();
        $this->hooks = new Hooks();
        $this->router = new Router();
        $this->registerServices();
        $this->configStamp = $this->readConfigStamp();
    }

    public static function boot(string $rootDir): self
    {
        $app = new self($rootDir);
        if ($app->settings()->isInstalled()) {
            $app->get(PluginManager::class)->loadEnabled($app);
        }
        $app->registerRoutes();
        $app->hooks->doAction('app.booted', $app);
        return $app;
    }

    private function registerServices(): void
    {
        $c = $this->container;
        $c->instance(self::class, $this);
        $c->instance(Hooks::class, $this->hooks);
        $c->instance(Router::class, $this->router);
        $c->set(Database::class, fn () => Database::fromEnv());
        $c->set(Settings::class, fn (Container $c) => new Settings($c->get(Database::class)));
        $c->set(Users::class, fn (Container $c) => new Users($c->get(Database::class), $this->hooks));
        $c->set(Auth::class, fn (Container $c) => new Auth($c->get(Database::class)));
        $c->set(Permissions::class, fn () => new Permissions($this->hooks));
        $c->set(Markup::class, fn () => new Markup($this->hooks));
        $c->set(Nodes::class, fn (Container $c) => new Nodes($c->get(Database::class), $this->hooks));
        $c->set(Broadcaster::class, function (Container $c): Broadcaster {
            $hub = getenv('MERCURE_PUBLISH_URL') ?: '';
            $secret = getenv('MERCURE_PUBLISHER_JWT_KEY') ?: '';
            if ($hub !== '' && $secret !== '') {
                return new MercureBroadcaster(
                    $hub,
                    getenv('MERCURE_PUBLIC_URL') ?: '/.well-known/mercure',
                    $secret,
                    getenv('MERCURE_TOPIC_PREFIX') ?: 'abb/',
                );
            }
            return new DatabaseBroadcaster($c->get(Database::class));
        });
        $c->set(Posts::class, fn (Container $c) => new Posts(
            $c->get(Database::class),
            $this->hooks,
            $c->get(Markup::class),
            $c->get(Nodes::class),
            $c->get(Broadcaster::class),
        ));
        $c->set(Themes::class, fn () => new Themes($this->rootDir . '/themes'));
        $c->set(View::class, fn (Container $c) => new View($this->rootDir, $c));
        $c->set(PluginManager::class, fn (Container $c) => new PluginManager($this->rootDir . '/plugins', $c->get(Database::class)));
    }

    private function registerRoutes(): void
    {
        $r = $this->router;
        $id = '{id:\d+(?:-[a-z0-9-]*)?}'; // "12" or "12-topic-title"

        $forum = new ForumController($this);
        $r->get('/', $forum->index(...), 'home');
        $r->get('/forum/' . $id, $forum->forum(...), 'forum');
        $r->add(['GET', 'POST'], '/forum/{id:\d+}/new', $forum->newTopic(...), 'topic.new');
        $r->get('/topic/' . $id, $forum->topic(...), 'topic');
        $r->post('/topic/{id:\d+}/reply', $forum->reply(...), 'topic.reply');
        $r->post('/topic/{id:\d+}/moderate', $forum->moderate(...), 'topic.moderate');
        $r->get('/topic/{id:\d+}/row', $forum->topicRow(...), 'topic.row');
        $r->get('/post/{id:\d+}', $forum->post(...), 'post');
        $r->get('/post/{id:\d+}/fragment', $forum->postFragment(...), 'post.fragment');
        $r->add(['GET', 'POST'], '/post/{id:\d+}/edit', $forum->edit(...), 'post.edit');
        $r->post('/post/{id:\d+}/delete', $forum->delete(...), 'post.delete');

        $auth = new AuthController($this);
        $r->add(['GET', 'POST'], '/login', $auth->login(...), 'login');
        $r->add(['GET', 'POST'], '/register', $auth->register(...), 'register');
        $r->post('/logout', $auth->logout(...), 'logout');
        $r->add(['GET', 'POST'], '/account', $auth->account(...), 'account');

        $admin = new AdminController($this);
        $r->get('/admin', $admin->dashboard(...), 'admin');
        $r->add(['GET', 'POST'], '/admin/structure', $admin->structure(...), 'admin.structure');
        $r->post('/admin/structure/{id:\d+}', $admin->structureItem(...), 'admin.structure.item');
        $r->add(['GET', 'POST'], '/admin/settings', $admin->settings(...), 'admin.settings');
        $r->add(['GET', 'POST'], '/admin/appearance', $admin->appearance(...), 'admin.appearance');
        $r->add(['GET', 'POST'], '/admin/plugins', $admin->plugins(...), 'admin.plugins');
        $r->add(['GET', 'POST'], '/admin/users', $admin->users(...), 'admin.users');

        $system = new SystemController($this);
        $r->get('/live/poll', $system->livePoll(...), 'live.poll');
        $r->get('/theme-assets/{theme}/{path:.+}', $system->themeAsset(...), 'theme.asset');
        $r->get('/healthz', $system->health(...), 'health');

        $install = new InstallController($this);
        $r->add(['GET', 'POST'], '/install', $install->install(...), 'install');

        // Plugins add or replace routes here. A route added later with the same name wins.
        $this->hooks->doAction('routes', $r, $this);
    }

    public function handle(Request $request): Response
    {
        $this->reloadIfConfigChanged();
        $view = $this->view();
        $ctx = null;
        try {
            $installed = $this->settings()->isInstalled();
            if (!$installed && $request->path !== '/install' && !str_starts_with($request->path, '/theme-assets/')) {
                return Response::redirect('/install');
            }
            $ctx = $installed ? $this->get(Auth::class)->context($request) : new Context($request, bin2hex(random_bytes(32)));
            $view->setContext($ctx);

            if ($request->isPost() && $request->path !== '/install' && !str_starts_with($request->path, '/api/')) {
                $this->get(Auth::class)->checkCsrf($ctx);
            }
            $this->hooks->doAction('request.start', $ctx);

            [$handler, $params] = $this->router->match($request->method === 'HEAD' ? 'GET' : $request->method, $request->path);
            $request->params = $params;
            $response = $handler($ctx);
        } catch (HttpException $e) {
            $response = $this->errorPage($e->status, $e->getMessage());
        } catch (Throwable $e) {
            error_log('AllianceBB: ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->get(Database::class)->reset();
            $detail = getenv('APP_DEBUG') === '1' ? $e->getMessage() : 'Something went wrong on our side.';
            $response = $this->errorPage(500, $detail);
        }

        if ($ctx !== null) {
            $secure = $request->scheme === 'https';
            foreach ($ctx->setCookies as [$name, $value, $expires]) {
                $response->withCookie($name, $value, $expires, $secure);
            }
            if ($ctx->nextFlash !== []) {
                $response->withCookie(Auth::FLASH_COOKIE, (string) json_encode($ctx->nextFlash), time() + 300, $secure);
            }
        }
        $response->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('referrer-policy', 'same-origin')
            ->withHeader('x-frame-options', 'SAMEORIGIN');
        $response = $this->hooks->applyFilters('response', $response, $ctx);
        $view->setContext(null);
        return $response;
    }

    private function errorPage(int $status, string $message): Response
    {
        try {
            return Response::html($this->view()->render('error.twig', ['status' => $status, 'message' => $message]), $status);
        } catch (Throwable) {
            return Response::text($message, $status);
        }
    }

    // ----- config reload (worker mode) ------------------------------------

    /** Call after changing plugins, theme or code so every worker restarts with the new setup. */
    public function touchConfig(): void
    {
        @mkdir($this->rootDir . '/var', 0775, true);
        file_put_contents($this->rootDir . '/var/config.stamp', (string) hrtime(true));
        $this->settings()->clearCache();
    }

    private function readConfigStamp(): int
    {
        $file = $this->rootDir . '/var/config.stamp';
        clearstatcache(true, $file);
        return is_file($file) ? (int) file_get_contents($file) : 0;
    }

    /** True when another worker changed the setup since this one booted. */
    public function configChanged(): bool
    {
        return $this->readConfigStamp() !== $this->configStamp;
    }

    private function reloadIfConfigChanged(): void
    {
        if ($this->configChanged()) {
            // Settings are cheap to reload; plugins and routes need a fresh worker (see public/index.php).
            $this->settings()->clearCache();
        }
    }

    // ----- accessors for controllers and plugins ---------------------------

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function view(): View
    {
        return $this->container->get(View::class);
    }

    public function settings(): Settings
    {
        return $this->container->get(Settings::class);
    }

    public function db(): Database
    {
        return $this->container->get(Database::class);
    }
}
