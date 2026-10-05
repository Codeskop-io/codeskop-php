<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Tests\Support\FakeTransport;

/** Real HTTP end to end: php -S app → SDK (curl and streams) → php -S mock ingest. */
final class PlainPhpTest extends \PHPUnit\Framework\TestCase
{
    /** @var list<resource> */
    private array $procs = [];
    private string $dir;

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p);
            proc_close($p);
        }
        $this->procs = [];
    }

    private function serve(string $router, int $port, array $env): void
    {
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:$port", $router];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
        $this->procs[] = $p;
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100000);
        }
        $this->fail("server on $port didn't start");
    }

    private function get(int $port, string $path, array $headers = []): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => implode("\r\n", $lines), 'timeout' => 10]]);
        $http_response_header = [];
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        preg_match('#\s(\d{3})\s#', $http_response_header[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), (string) $body];
    }

    /** @return list<array> */
    private function events(int $want): array
    {
        $events = [];
        for ($i = 0; $i < 50; $i++) {
            $events = [];
            foreach (glob($this->dir . '/*.json') as $f) {
                $env = json_decode(file_get_contents($f), true);
                FakeTransport::validate(gzencode(json_encode($env)));
                array_push($events, ...$env['batch']);
            }
            if (count($events) >= $want) {
                break;
            }
            usleep(100000);
        }
        return $events;
    }

    public static function transports(): array
    {
        return ['curl' => [false], 'streams' => [true]];
    }

    /** @dataProvider transports */
    public function testPlainPhpEndToEnd(bool $streams): void
    {
        $this->dir = sys_get_temp_dir() . '/codeskop-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        mkdir($this->dir . '/cache');
        $ingest = random_int(20000, 30000);
        $app = $ingest + 1;
        $this->serve(__DIR__ . '/fixtures/ingest.php', $ingest, ['INGEST_DIR' => $this->dir]);
        $this->serve(__DIR__ . '/fixtures/app.php', $app, ['INGEST_URL' => "http://127.0.0.1:$ingest", 'CACHE_DIR' => $this->dir . '/cache', 'USE_STREAMS' => $streams ? '1' : '']);

        $this->assertSame([200, '{"id":5}'], $this->get($app, '/orders/5', ['X-API-Key' => 'partner']));
        $this->assertSame(500, $this->get($app, '/boom')[0]);
        $this->assertSame(500, $this->get($app, '/fatal')[0]);
        $this->assertSame(500, $this->get($app, '/oom')[0]);
        $this->assertSame([403, '{"error":"consumer_blocked"}'], $this->get($app, '/orders/6', ['X-API-Key' => 'reseller']));

        $events = $this->events(8);
        $requests = [];
        $exceptions = [];
        foreach ($events as $e) {
            if ($e['type'] === 'http_request') {
                $requests[$e['payload']['request_id']] = [$e['payload']['route'], $e['payload']['status'], $e['payload']['blocked'] ?? false, $e['user']['id'] ?? null];
            } else {
                $exceptions[] = [$e['payload']['exception_class'], $e['payload']['mechanism'], $e['payload']['request']['route'] ?? null];
            }
        }
        sort($requests);
        $this->assertSame([
            ['/boom', 500, false, null],
            ['/fatal', 500, false, null],
            ['/oom', 500, false, null],
            ['/orders/{id}', 200, false, 'u-5'],
            ['/orders/{id}', 403, true, null],
        ], array_values($requests));
        sort($exceptions);
        $this->assertSame([
            ['Error', 'uncaught', '/fatal'],
            ['FatalError', 'fatal', '/oom'],
            ['UnexpectedValueException', 'uncaught', '/boom'],
        ], $exceptions);
        $consumer = array_values(array_filter($events, static fn ($e) => isset($e['payload']['consumer'])));
        $this->assertCount(2, $consumer);
        $this->assertStringNotContainsString('reseller', json_encode($events));
    }
}
