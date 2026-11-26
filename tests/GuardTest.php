<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Guard;
use Runio\ScriptUi\Http\Request;

final class GuardTest extends TestCase
{
    /** @return iterable<string, array{string, array<string,string>, bool}> */
    public static function requests(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1', ['host' => 'localhost'], true];
        yield 'with a port' => ['127.0.0.1', ['host' => 'localhost:8765'], true];
        yield 'address as host' => ['127.0.0.1', ['host' => '127.0.0.1:8000'], true];
        yield 'ipv6 loopback' => ['::1', ['host' => '[::1]:8000'], true];
        yield 'another 127 address' => ['127.0.0.53', ['host' => 'localhost'], true];
        yield 'ipv4 mapped into ipv6' => ['::ffff:127.0.0.1', ['host' => 'localhost'], true];
        yield 'a name under localhost' => ['127.0.0.1', ['host' => 'tools.localhost:8000'], true];
        yield 'upper case host' => ['127.0.0.1', ['host' => 'LOCALHOST:8000'], true];

        yield 'client on the lan' => ['192.168.1.20', ['host' => 'localhost'], false];
        yield 'client on the internet' => ['203.0.113.9', ['host' => 'localhost'], false];
        yield 'text that starts like loopback' => ['127.0.0.1.evil.example', ['host' => 'localhost'], false];
        yield 'no address' => ['', ['host' => 'localhost'], false];
        yield 'foreign host name' => ['127.0.0.1', ['host' => 'evil.example.com'], false];
        yield 'host that ends like localhost' => ['127.0.0.1', ['host' => 'notlocalhost'], false];
        yield 'host that starts like localhost' => ['127.0.0.1', ['host' => 'localhost.evil.example'], false];
        yield 'host with junk' => ['127.0.0.1', ['host' => 'localhost:8000@evil.example'], false];
        yield 'no host header' => ['127.0.0.1', [], false];
        yield 'x-forwarded-for' => ['127.0.0.1', ['host' => 'localhost', 'x-forwarded-for' => '203.0.113.9'], false];
        yield 'forwarded' => ['127.0.0.1', ['host' => 'localhost', 'forwarded' => 'for=203.0.113.9'], false];
        yield 'x-real-ip' => ['127.0.0.1', ['host' => 'localhost', 'x-real-ip' => '203.0.113.9'], false];
        yield 'via' => ['127.0.0.1', ['host' => 'localhost', 'via' => '1.1 proxy'], false];
        yield 'x-forwarded-host' => ['127.0.0.1', ['host' => 'localhost', 'x-forwarded-host' => 'tools.example.com'], false];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('requests')]
    public function testOnlyRequestsFromThisMachineAreLocal(string $address, array $headers, bool $expected): void
    {
        $request = new Request('GET', '/', [], [], $headers, $address);

        self::assertSame($expected, Guard::isLocal($request));
    }

    public function testAProxyThatAddsNoHeaderStillGivesItselfAway(): void
    {
        // nginx with a bare "proxy_pass http://127.0.0.1:8765": it connects from this
        // machine, puts our own address in the host header and adds no forwarding
        // header. It does close the connection after every request, and before
        // version 1.29.7 it speaks HTTP/1.0. No browser does either.
        $oldProtocol = new Request('GET', '/', [], [], ['host' => '127.0.0.1:8765'], '127.0.0.1', false, 'HTTP/1.0');
        self::assertFalse(Guard::isLocal($oldProtocol));

        foreach (['close', 'Close', 'TE, close', 'keep-alive, close'] as $connection) {
            $closing = new Request('GET', '/', [], [], ['host' => '127.0.0.1:8765', 'connection' => $connection], '127.0.0.1');
            self::assertFalse(Guard::isLocal($closing), $connection);
        }
    }

    public function testABrowserOnThisMachineIsLocal(): void
    {
        foreach (['HTTP/1.1', 'HTTP/2.0', 'HTTP/3.0'] as $protocol) {
            $browser = new Request('GET', '/', [], [], ['host' => 'localhost:8765', 'connection' => 'keep-alive'], '127.0.0.1', false, $protocol);
            self::assertTrue(Guard::isLocal($browser), $protocol);
        }

        // A word that only contains "close" is not the instruction to close.
        $odd = new Request('GET', '/', [], [], ['host' => 'localhost', 'connection' => 'x-enclosed'], '127.0.0.1');
        self::assertTrue(Guard::isLocal($odd));
    }

    public function testHeaderNamesAreNotCaseSensitive(): void
    {
        $request = new Request('GET', '/', [], [], ['Host' => 'localhost', 'X-Forwarded-For' => '203.0.113.9'], '127.0.0.1');

        self::assertSame('localhost', $request->header('HOST'));
        self::assertFalse(Guard::isLocal($request));
    }
}
