<?php

declare(strict_types=1);

namespace AllianceBB\Tests;

use AllianceBB\Controller\ApiController;
use AllianceBB\Http\Request;
use AllianceBB\Service\Throttle;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
    }

    public function testApiPaths(): void
    {
        self::assertTrue(ApiController::isApiPath('/api/v1'));
        self::assertTrue(ApiController::isApiPath('/api/v1/forums'));
        self::assertFalse(ApiController::isApiPath('/api/v10'));
        self::assertFalse(ApiController::isApiPath('/api/alliance/v1/info'));
    }

    public function testErrorBodyShape(): void
    {
        $r = ApiController::errorResponse(422, 'Nope');
        self::assertSame(422, $r->status);
        self::assertSame(['error' => ['code' => 'invalid', 'message' => 'Nope']], json_decode($r->body, true));
    }

    public function testForwardedIpIgnoredUnlessTrusted(): void
    {
        self::assertSame('10.0.0.5', Request::clientIp('10.0.0.5', '203.0.113.9'));
        putenv('TRUSTED_PROXIES=private');
        self::assertSame('203.0.113.9', Request::clientIp('10.0.0.5', '1.1.1.1, 203.0.113.9'));
        // A public peer is the visitor itself, whatever header it sends.
        self::assertSame('198.51.100.7', Request::clientIp('198.51.100.7', '203.0.113.9'));
        self::assertSame('10.0.0.5', Request::clientIp('10.0.0.5', 'garbage'));
    }

    public function testThrottleKeysNormaliseLogin(): void
    {
        self::assertSame(['ip:1.2.3.4', 'login:alice'], Throttle::keys('1.2.3.4', '  Alice '));
    }
}
