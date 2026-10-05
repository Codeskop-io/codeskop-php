<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Codeskop;
use Codeskop\Laravel\CodeskopServiceProvider;
use Codeskop\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LaravelTest extends \Orchestra\Testbench\TestCase
{
    private FakeTransport $t;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->t = new FakeTransport();
        $this->cacheDir = sys_get_temp_dir() . '/codeskop-lv-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    protected function getPackageProviders($app): array
    {
        return [CodeskopServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->t->config['api_trust'] = ['enabled' => true, 'salt' => 'salt', 'blocking' => true, 'consumer_sources' => [['type' => 'header', 'name' => 'X-API-Key']]];
        $this->t->verdicts = ['blocked' => [substr(hash_hmac('sha256', 'reseller-key', 'salt'), 0, 32)]];
        $app['config']->set('codeskop', [
            'api_key' => 'cs_test_pk_testtesttest', 'endpoint' => 'https://ingest.test', 'enabled' => true,
            'transport' => $this->t, 'cache_dir' => $this->cacheDir, 'ignore_routes' => ['/up'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/api/orders/{order}', fn (string $order) => response()->json(['id' => $order]));
        Route::get('/api/boom/{id}', function () {
            throw new \LengthException('laravel e2e boom');
        });
        Route::get('/api/missing', fn () => throw new NotFoundHttpException());
        Route::get('/api/outgoing', fn () => Http::get('https://partner.example.com/v2/rates/9')->status());
        Route::get('/up', fn () => 'ok');
    }

    private function terminateAndFlush(): void
    {
        Codeskop::flush();
    }

    public function testRecordsRoutesExceptionsAndBlocks(): void
    {
        Http::fake(['partner.example.com/*' => Http::response('{}', 502)]);
        $this->get('/api/orders/41', ['X-Request-Id' => 'abc'])->assertOk();
        $this->get('/api/boom/3')->assertStatus(500);
        $this->get('/api/missing')->assertStatus(404);
        $this->get('/api/orders/1', ['X-API-Key' => 'reseller-key'])->assertStatus(403)->assertExactJson(['error' => 'consumer_blocked']);
        $this->get('/api/orders/2', ['X-API-Key' => 'partner-key'])->assertOk();
        $this->get('/api/outgoing')->assertOk();
        $this->get('/up')->assertOk();
        $this->terminateAndFlush();

        $reqs = array_map(static fn ($e) => [$e['payload']['route'], $e['payload']['status'], $e['payload']['blocked'] ?? false], $this->t->events('http_request'));
        $this->assertSame([
            ['/api/orders/{order}', 200, false],
            ['/api/boom/{id}', 500, false],
            ['/api/missing', 404, false],
            ['/api/orders/{order}', 403, true],
            ['/api/orders/{order}', 200, false],
            ['/api/outgoing', 200, false],
        ], $reqs);
        $first = $this->t->events('http_request')[0]['payload'];
        $this->assertSame('abc', $first['request_id']);

        $exceptions = $this->t->events('exception');
        $this->assertCount(1, $exceptions, 'NotFoundHttpException is in dontReport');
        $exc = $exceptions[0];
        $this->assertSame('LengthException', $exc['payload']['exception_class']);
        $this->assertSame('laravel', $exc['payload']['mechanism']);
        $this->assertFalse($exc['payload']['handled']);
        $this->assertSame('/api/boom/{id}', $exc['payload']['request']['route']);
        $this->assertTrue($exc['payload']['stacktrace'][0]['in_app']);
        $this->assertStringStartsWith('laravel ', $this->t->envelopes()[0]['context']['app']['framework']);

        $err = $this->t->events('api_error')[0]['payload'];
        $this->assertSame(['partner.example.com', '/v2/rates/{id}', 502], [$err['host'], $err['path'], $err['status']]);
    }

    public function testAuthenticatedUserId(): void
    {
        Route::get('/api/me', fn () => 'me');
        $user = new \Illuminate\Auth\GenericUser(['id' => 77]);
        $this->actingAs($user)->get('/api/me')->assertOk();
        Codeskop::flush();
        $this->assertSame('77', $this->t->events('http_request')[0]['user']['id']);
    }
}
