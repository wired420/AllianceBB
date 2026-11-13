<?php

declare(strict_types=1);

namespace AllianceBB\Tests;

use AllianceBB\Federation\Federation;
use AllianceBB\Federation\Signer;
use PHPUnit\Framework\TestCase;

final class FederationTest extends TestCase
{
    public function testSignedRequestVerifies(): void
    {
        $secret = Signer::newSecret();
        $h = Signer::headers('abb_x', $secret, 'POST', '/api/alliance/v1/subscribe', '{"a":1}');
        self::assertTrue(Signer::verify($secret, 'POST', '/api/alliance/v1/subscribe', $h['X-Alliance-Timestamp'], $h['X-Alliance-Nonce'], '{"a":1}', $h['X-Alliance-Signature']));
    }

    public function testTamperedBodyOrPathFails(): void
    {
        $secret = Signer::newSecret();
        $h = Signer::headers('abb_x', $secret, 'POST', '/a', 'body');
        self::assertFalse(Signer::verify($secret, 'POST', '/a', $h['X-Alliance-Timestamp'], $h['X-Alliance-Nonce'], 'BODY', $h['X-Alliance-Signature']));
        self::assertFalse(Signer::verify($secret, 'POST', '/b', $h['X-Alliance-Timestamp'], $h['X-Alliance-Nonce'], 'body', $h['X-Alliance-Signature']));
        self::assertFalse(Signer::verify('other', 'POST', '/a', $h['X-Alliance-Timestamp'], $h['X-Alliance-Nonce'], 'body', $h['X-Alliance-Signature']));
    }

    public function testOldTimestampFails(): void
    {
        $secret = Signer::newSecret();
        $ts = (string) (time() - 3600);
        $nonce = bin2hex(random_bytes(16));
        $sig = Signer::sign($secret, 'GET', '/a', $ts, $nonce, '');
        self::assertFalse(Signer::verify($secret, 'GET', '/a', $ts, $nonce, '', $sig));
    }

    public function testHandlesDropSpaces(): void
    {
        self::assertSame('GamingHub', Federation::baseHandle('Gaming Hub'));
        self::assertSame('BetaBoard', Federation::baseHandle('Beta Board!'));
        self::assertSame('site', Federation::baseHandle('   '));
    }

    public function testConnectionCodeRoundTripAndRejectsGarbage(): void
    {
        $data = ['v' => 1, 'url' => 'https://a.test', 'name' => 'A', 'node' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'key' => 'abb_1', 'secret' => 's'];
        $code = 'abb1.' . rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        self::assertSame('https://a.test', Federation::parseCode("  $code \n")['url']);
        $this->expectException(\InvalidArgumentException::class);
        Federation::parseCode('hello');
    }
}
