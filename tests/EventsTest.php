<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Events;

final class EventsTest extends \PHPUnit\Framework\TestCase
{
    public function testNormalizePath(): void
    {
        $this->assertSame('/orders/{id}/items', Events::normalizePath('/orders/42/items?x=1'));
        $this->assertSame('/u/{id}', Events::normalizePath('/u/0b6f5f0e-6c3a-4b8e-9a43-1d2f3e4a5b6c'));
        $this->assertSame('/t/{id}', Events::normalizePath('/t/abcdef0123456789abcd'));
        $this->assertSame('/t/abc', Events::normalizePath('/t/abc'));
        $this->assertSame('/', Events::normalizePath(''));
    }

    public function testTemplateRoute(): void
    {
        $this->assertSame('/api/orders/{order}', Events::templateRoute('api/orders/{order}'));
        $this->assertSame('/users/{user}', Events::templateRoute('/users/{user?}'));
        $this->assertSame('/files/{path}', Events::templateRoute('/files/{path:.*}'));
        $this->assertSame('/u/{id}', Events::templateRoute('/u/:id'));
        $this->assertSame('/', Events::templateRoute('/'));
    }

    public function testFramesInnermostFirstWithCause(): void
    {
        try {
            $this->outer();
        } catch (\Throwable $e) {
            $p = Events::exceptionPayload($e, dirname(__DIR__));
        }
        $this->assertSame(\RuntimeException::class, $p['exception_class']);
        $this->assertSame('outer failed', $p['message']);
        $first = $p['stacktrace'][0];
        $this->assertSame(self::class, $first['class']);
        $this->assertSame('outer', $first['method']);
        $this->assertSame('tests/EventsTest.php', $first['file']);
        $this->assertTrue($first['in_app']);
        $this->assertSame('inner', $p['cause']['stacktrace'][0]['method']);
        $this->assertSame(\LogicException::class, $p['cause']['exception_class']);
        $vendor = array_filter($p['stacktrace'], static fn ($f) => str_starts_with($f['file'], 'vendor/'));
        $this->assertNotEmpty($vendor);
        foreach ($vendor as $f) {
            $this->assertFalse($f['in_app']);
        }
    }

    private function outer(): void
    {
        try {
            $this->inner();
        } catch (\LogicException $e) {
            throw new \RuntimeException('outer failed', 0, $e);
        }
    }

    private function inner(): void
    {
        throw new \LogicException('inner');
    }

    public function testOutgoingEvents(): void
    {
        [$timing, $error] = Events::outgoing('get', 'api.example.com', '/v1/users/12', 503, 12.5, null, 'u1');
        $this->assertSame('/v1/users/{id}', $timing['payload']['path']);
        $this->assertArrayNotHasKey('error_kind', $timing['payload']);
        $this->assertSame('http_5xx', $error['payload']['error_kind']);
        $this->assertSame('u1', $error['user']['id']);
        $this->assertCount(1, Events::outgoing('GET', 'h', '/', 200, 1, null, null));
        $this->assertSame('timeout', Events::outgoing('GET', 'h', '/', 0, 1, 'timeout', null)[1]['payload']['error_kind']);
    }

    public function testRequestEventSeverity(): void
    {
        $this->assertSame('low', Events::request('get', '/a', 200, 1, null, 10, 'r', false, null, [])['severity']);
        $this->assertSame('high', Events::request('get', '/a', 502, 1, null, 10, 'r', false, null, [])['severity']);
        $this->assertSame('high', Events::request('get', '/a', 200, 1, null, 10, 'r', true, null, [])['severity']);
    }
}
