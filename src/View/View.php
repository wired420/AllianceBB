<?php

declare(strict_types=1);

namespace AllianceBB\View;

use AllianceBB\Core\Container;
use AllianceBB\Core\Context;
use AllianceBB\Core\Hooks;
use AllianceBB\Http\Router;
use AllianceBB\Live\Broadcaster;
use AllianceBB\Service\Permissions;
use AllianceBB\Service\Settings;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig rendering with theme inheritance: the active theme's templates are
 * looked up first, then its parent theme's, then the default theme's.
 * Plugins can prepend their own template folders to override any template,
 * and get a namespace of their own (@plugin-name/...).
 */
final class View
{
    private ?Environment $twig = null;
    private ?Context $ctx = null;

    /** @var list<string> template folders prepended by plugins */
    private array $overridePaths = [];

    /** @var array<string, string> plugin namespaces */
    private array $namespaces = [];

    /**
     * Services are looked up lazily from the container, so plugins can still
     * replace them after registering templates during boot.
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly Container $container,
    ) {
    }

    public function addOverridePath(string $path): void
    {
        $this->overridePaths[] = $path;
        $this->twig = null;
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace] = $path;
        $this->twig = null;
    }

    /** The context of the request being rendered; set by the app at the start of each request. */
    public function setContext(?Context $ctx): void
    {
        $this->ctx = $ctx;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $template = (string) $this->hooks()->applyFilters('view.template', $template, $data);
        $data = (array) $this->hooks()->applyFilters('view.data', $data, $template);
        return $this->twig()->render($template, $data);
    }

    public function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }
        $loader = new FilesystemLoader([], $this->rootDir);
        foreach (array_reverse($this->overridePaths) as $path) {
            $loader->addPath($path);
        }
        foreach ($this->themes()->templateChain((string) $this->settings()->get('theme.name')) as $path) {
            $loader->addPath($path);
        }
        foreach ($this->namespaces as $ns => $path) {
            $loader->addPath($path, $ns);
        }

        $twig = new Environment($loader, [
            'cache' => $this->rootDir . '/var/cache/twig',
            'auto_reload' => true,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $twig->addFunction(new TwigFunction('url', fn (string $name, array $params = []) => $this->router()->url($name, $params)));
        $twig->addFunction(new TwigFunction('csrf_field', fn () => '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars($this->ctx?->csrfToken ?? '', ENT_QUOTES) . '">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('can', fn (string $ability, ?array $subject = null) => $this->ctx !== null
            && $this->permissions()->can($this->ctx, $ability, $subject)));
        $twig->addFunction(new TwigFunction('setting', fn (string $key) => $this->settings()->get($key)));
        $twig->addFunction(new TwigFunction('theme_asset', fn (string $path) => '/theme-assets/'
            . rawurlencode((string) $this->settings()->get('theme.name')) . '/' . ltrim($path, '/')));
        // {{ hook('post.after', post) }} lets plugins inject HTML at that spot.
        $twig->addFunction(new TwigFunction(
            'hook',
            fn (string $name, mixed ...$args) => (string) $this->hooks()->applyFilters('render.' . $name, '', ...$args, ...[$this->ctx]),
            ['is_safe' => ['html']],
        ));
        $twig->addFunction(new TwigFunction('theme_css_vars', fn () => $this->themes()->cssVariables(
            (string) $this->settings()->get('theme.name'),
            (array) $this->settings()->get('theme.colors'),
        ), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('live_config', fn () => $this->live()->clientConfig()));
        $twig->addFilter(new TwigFilter('ago', [self::class, 'ago']));
        $twig->addFilter(new TwigFilter('repeat', fn (string $s, mixed $n) => str_repeat($s, max(0, (int) $n))));
        $twig->addFilter(new TwigFilter('author', [self::class, 'authorName']));

        $twig->addGlobal('app', new class ($this) {
            public function __construct(private readonly View $view)
            {
            }

            public function ctx(): ?Context
            {
                return $this->view->context();
            }
        });

        $this->hooks()->doAction('view.twig', $twig);
        return $this->twig = $twig;
    }

    public function context(): ?Context
    {
        return $this->ctx;
    }

    public static function ago(mixed $time): string
    {
        if ($time === null || $time === '') {
            return '';
        }
        $ts = is_numeric($time) ? (int) $time : strtotime((string) $time);
        $diff = time() - (int) $ts;
        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => intdiv($diff, 60) . ' min ago',
            $diff < 86400 => intdiv($diff, 3600) . ' h ago',
            $diff < 86400 * 7 => intdiv($diff, 86400) . ' d ago',
            default => date('M j, Y', (int) $ts),
        };
    }

    /** @param array<string, mixed> $row a post row with author columns */
    public static function authorName(array $row): string
    {
        return (string) ($row['author_handle'] ?? $row['display_name'] ?? $row['username'] ?? 'deleted user');
    }
    private function themes(): Themes
    {
        return $this->container->get(Themes::class);
    }

    private function settings(): Settings
    {
        return $this->container->get(Settings::class);
    }

    private function hooks(): Hooks
    {
        return $this->container->get(Hooks::class);
    }

    private function router(): Router
    {
        return $this->container->get(Router::class);
    }

    private function permissions(): Permissions
    {
        return $this->container->get(Permissions::class);
    }

    private function live(): Broadcaster
    {
        return $this->container->get(Broadcaster::class);
    }
}
