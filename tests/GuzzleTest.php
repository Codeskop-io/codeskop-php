<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Codeskop;
use Codeskop\Guzzle\Middleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class GuzzleTest extends TestCase
{
    public function testOutgoingCallsBecomeTimingAndErrors(): void
    {
        $this->client();
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
            new Response(503, [], 'down'),
            new ConnectException('Connection timed out', new Request('GET', 'https://pay.example.com/x')),
            new Response(200),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::create());
        $http = new Client(['handler' => $stack, 'http_errors' => true]);
        $http->get('https://pay.example.com/v1/charges/123?secret=1');
        try {
            $http->post('https://pay.example.com/v1/charges', ['body' => 'abc']);
        } catch (\Throwable) {
        }
        try {
            $http->get('https://pay.example.com/x');
        } catch (\Throwable) {
        }
        $http->get('https://ingest.test/v1/events'); // the Codeskop endpoint itself is never instrumented
        Codeskop::flush();
        $got = array_map(static fn ($e) => [$e['type'], $e['payload']['method'], $e['payload']['path'], $e['payload']['status'] ?? null, $e['payload']['error_kind'] ?? null], $this->t->events());
        $this->assertSame([
            ['api_timing', 'GET', '/v1/charges/{id}', 200, null],
            ['api_timing', 'POST', '/v1/charges', 503, null],
            ['api_error', 'POST', '/v1/charges', 503, 'http_5xx'],
            ['api_timing', 'GET', '/x', null, null],
            ['api_error', 'GET', '/x', null, 'timeout'],
        ], $got);
        $this->assertSame('pay.example.com', $this->t->events()[0]['payload']['host']);
        $this->assertSame(3, $this->t->events()[1]['payload']['request_bytes']);
    }
}
