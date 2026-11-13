<?php

declare(strict_types=1);

namespace AllianceBB\Tests;

use AllianceBB\Core\Container;
use AllianceBB\Core\Hooks;
use AllianceBB\Core\Slug;
use AllianceBB\Core\Uuid;
use AllianceBB\Http\HttpException;
use AllianceBB\Http\Router;
use AllianceBB\View\Themes;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public function testFiltersRunInPriorityOrder(): void
    {
        $hooks = new Hooks();
        $hooks->addFilter('x', fn (string $v) => $v . 'b', 20);
        $hooks->addFilter('x', fn (string $v) => $v . 'a', 5);
        self::assertSame('_ab', $hooks->applyFilters('x', '_'));
    }

    public function testActionsReceiveArguments(): void
    {
        $hooks = new Hooks();
        $seen = [];
        $hooks->addAction('saved', function (int $id) use (&$seen) { $seen[] = $id; });
        $hooks->doAction('saved', 7);
        self::assertSame([7], $seen);
    }

    public function testContainerOverrideWrapsPrevious(): void
    {
        $c = new Container();
        $c->set('greeting', fn () => 'hello');
        $c->override('greeting', fn (Container $c, callable $previous) => strtoupper($previous($c)));
        self::assertSame('HELLO', $c->get('greeting'));
    }

    public function testRouterMatchesAndBuildsSlugUrls(): void
    {
        $router = new Router();
        $router->get('/topic/{id:\d+(?:-[a-z0-9-]*)?}', fn () => 'ok', 'topic');
        [, $params] = $router->match('GET', '/topic/12-hello-world');
        self::assertSame('12-hello-world', $params['id']);
        self::assertSame('/topic/12-hello-world', $router->url('topic', ['id' => '12-hello-world']));
        $this->expectException(HttpException::class);
        $router->match('GET', '/topic/abc');
    }

    public function testUuidV7IsValidAndTimeOrdered(): void
    {
        $a = Uuid::v7();
        usleep(2000);
        $b = Uuid::v7();
        self::assertTrue(Uuid::isValid($a));
        self::assertSame('7', $a[14]);
        self::assertLessThan(0, strcmp($a, $b));
    }

    public function testSlug(): void
    {
        self::assertSame('hello-world', Slug::make('  Hello, World!  '));
        self::assertSame('item', Slug::make('!!!'));
    }

    public function testThemeColorValidation(): void
    {
        self::assertTrue(Themes::validColor('#a1b2c3'));
        self::assertFalse(Themes::validColor('red;}body{display:none'));
    }
}
