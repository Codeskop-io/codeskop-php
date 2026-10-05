<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Client;
use Codeskop\Codeskop;
use Codeskop\Events;
use Codeskop\IncomingRequest;

final class ClientTest extends TestCase
{
    public function testRejectsSecretAndMalformedKeys(): void
    {
        $this->assertStringContainsString('secret key', (string) Client::keyProblem('cs_live_sk_abcdefghijkl'));
        $this->assertNotNull(Client::keyProblem('nope'));
        $this->assertNull(Client::keyProblem('cs_live_pk_Ex4mpleKey123'));
        $c = $this->client(['api_key' => 'cs_live_sk_abcdefghijkl']);
        $this->assertFalse($c->enabled());
        Codeskop::captureMessage('x');
        $this->assertTrue(Codeskop::flush());
        $this->assertSame([], $this->t->requests);
    }

    public function testCaptureAndFlushSendsValidGzipEnvelope(): void
    {
        $c = $this->client(['release' => 'r1', 'environment' => 'staging']);
        Codeskop::setUser('user-7');
        Codeskop::captureException(new \DomainException('bad total'), null, ['area' => 'checkout']);
        Codeskop::captureMessage('hello');
        $this->assertTrue(Codeskop::flush());
        [$env] = $this->t->envelopes();
        $this->assertSame('codeskop-php', $env['context']['app']['sdk_name']);
        $this->assertSame('r1', $env['context']['app']['release']);
        $this->assertSame('staging', $env['context']['app']['environment']);
        [$exc, $msg] = $env['batch'];
        $this->assertSame('DomainException', $exc['payload']['exception_class']);
        $this->assertTrue($exc['payload']['handled']);
        $this->assertSame('medium', $exc['severity']);
        $this->assertSame(['area' => 'checkout'], $exc['payload']['tags']);
        $this->assertSame('user-7', $exc['user']['id']);
        $this->assertSame('Message', $msg['payload']['exception_class']);
        $post = array_values(array_filter($this->t->requests, static fn ($r) => $r['method'] === 'POST'))[0];
        $this->assertSame('gzip', $post['headers']['Content-Encoding']);
        $this->assertSame(0, $c->queued());
    }

    public function testBatchesOfAtMost100(): void
    {
        $this->client();
        for ($i = 0; $i < 250; $i++) {
            Codeskop::captureMessage("m$i");
        }
        // CLI: a full batch is sent as soon as 100 are queued.
        $this->assertSame(2, $this->t->count('/v1/events'));
        Codeskop::flush();
        $this->assertSame([100, 100, 50], array_map(static fn ($e) => count($e['batch']), $this->t->envelopes()));
    }

    public function testOversizedEventDroppedAndLargeBatchSplit(): void
    {
        $c = $this->client(['flush_timeout' => 10]);
        $c->capture(Events::event('exception', 'high', ['exception_class' => 'X', 'message' => str_repeat('a', 70000), 'stacktrace' => []]));
        for ($i = 0; $i < 30; $i++) {
            $c->capture(Events::event('exception', 'high', ['exception_class' => 'X', 'message' => 'm', 'stacktrace' => [], 'blob' => base64_encode(random_bytes(45000))]));
        }
        Codeskop::flush(10);
        $events = $this->t->events();
        $this->assertCount(30, $events);
        $this->assertGreaterThan(1, count($this->t->envelopes()));
    }

    public function testRetryAfterThenSuccess(): void
    {
        $this->client();
        $this->t->eventResponses = [['status' => 429, 'headers' => ['retry-after' => '0.1'], 'body' => '']];
        Codeskop::captureMessage('x');
        $start = microtime(true);
        $this->assertTrue(Codeskop::flush(3));
        $this->assertGreaterThanOrEqual(0.09, microtime(true) - $start);
        $this->assertSame(2, $this->t->count('/v1/events'));
        $this->assertSame($this->t->events()[0]['event_id'], $this->t->events()[1]['event_id'], 'retries resend the same event_id');
    }

    public function testLongRetryAfterBacksOffAcrossRequests(): void
    {
        $this->client();
        $this->t->eventResponses = [['status' => 503, 'headers' => ['retry-after' => '120'], 'body' => '']];
        Codeskop::captureMessage('x');
        $this->assertFalse(Codeskop::flush(1));
        // A later request (new client, same cache) doesn't hit ingest during the back-off.
        $this->client();
        Codeskop::captureMessage('y');
        $this->assertFalse(Codeskop::flush(1));
        $this->assertSame(1, $this->t->count('/v1/events'));
    }

    public function testServerErrorBacksOffExponentially(): void
    {
        $this->client();
        $this->t->eventResponses = [['status' => 500, 'headers' => [], 'body' => ''], ['status' => 502, 'headers' => [], 'body' => '']];
        Codeskop::captureMessage('x');
        $this->assertTrue(Codeskop::flush(5)); // waits ~1 s then ~2 s
        $this->assertSame(3, $this->t->count('/v1/events'));
    }

    public function testClientErrorDropsBatch(): void
    {
        $this->client();
        $this->t->eventResponses = [['status' => 400, 'headers' => [], 'body' => '']];
        Codeskop::captureMessage('x');
        $this->assertTrue(Codeskop::flush());
        $this->assertSame(1, $this->t->count('/v1/events'));
    }

    public function testSamplingNeverDropsErrorsOrAttributedRequests(): void
    {
        $this->t->config['sample_rates'] = ['http_request' => 0, 'api_timing' => 0];
        $c = $this->client();
        $c->capture(Events::request('GET', '/a', 200, 1, null, 1, 'r', false, null, []));
        $c->capture(Events::request('GET', '/a', 200, 1, null, 1, 'r', false, null, ['consumer' => ['id_hash' => 'x']]));
        $c->capture(Events::request('GET', '/a', 500, 1, null, 1, 'r', false, null, []));
        foreach (Events::outgoing('GET', 'h', '/', 404, 1, null, null) as $e) {
            $c->capture($e);
        }
        Codeskop::captureMessage('m');
        Codeskop::flush();
        $types = array_map(static fn ($e) => $e['type'] . ':' . ($e['payload']['status'] ?? ''), $this->t->events());
        $this->assertSame(['http_request:200', 'http_request:500', 'api_error:404', 'exception:'], $types);
    }

    public function testHttpRequestRateFallsBackToApiTiming(): void
    {
        $this->t->config['sample_rates'] = ['api_timing' => 0.25];
        $c = $this->client();
        $this->assertSame(0.25, $c->sampleRate('http_request'));
        $this->t->config['sample_rates'] = ['api_timing' => 0.25, 'http_request' => 0.75];
        array_map('unlink', glob($this->cacheDir . '/*'));
        $this->assertSame(0.75, $this->client()->sampleRate('http_request'));
    }

    public function testNetworkFeatureOffAndRemoteDisabled(): void
    {
        $this->t->config['features']['network'] = false;
        $c = $this->client();
        $c->capture(Events::request('GET', '/a', 500, 1, null, 1, 'r', true, null, []));
        Codeskop::captureMessage('kept');
        Codeskop::flush();
        $this->assertSame(['exception'], array_column($this->t->events(), 'type'));

        array_map('unlink', glob($this->cacheDir . '/*'));
        $this->t->config = ['enabled' => false];
        $this->client();
        Codeskop::captureMessage('dropped');
        Codeskop::flush();
        $this->assertCount(1, $this->t->events());
    }

    public function testConfigIsCachedAcrossRequestsAndRefreshedAfterTheResponse(): void
    {
        $this->client();
        Codeskop::captureMessage('a');
        $this->client();
        Codeskop::captureMessage('b');
        $this->assertSame(1, $this->t->count('/v1/config'));
        // Stale cache: served immediately, refreshed at flush time with If-None-Match.
        $file = glob($this->cacheDir . '/*config.json')[0];
        $c = json_decode(file_get_contents($file), true);
        $c['at'] = time() - 3600;
        file_put_contents($file, json_encode($c));
        $this->client();
        Codeskop::captureMessage('c');
        $this->assertSame(1, $this->t->count('/v1/config'));
        Codeskop::flush();
        $this->assertSame(2, $this->t->count('/v1/config'));
        $last = array_values(array_filter($this->t->requests, static fn ($r) => str_ends_with($r['url'], '/v1/config')))[1];
        $this->assertSame('"c1"', $last['headers']['If-None-Match']);
    }

    public function testBeforeSendAndIgnoreExceptions(): void
    {
        $this->client([
            'ignore_exceptions' => [\InvalidArgumentException::class],
            'before_send' => static function (array $e) {
                if (($e['payload']['message'] ?? '') === 'drop') {
                    return null;
                }
                $e['payload']['message'] = str_replace('secret', '***', $e['payload']['message']);
                return $e;
            },
        ]);
        Codeskop::captureException(new \InvalidArgumentException('ignored'));
        Codeskop::captureMessage('drop');
        Codeskop::captureMessage('a secret');
        Codeskop::flush();
        $this->assertSame(['a ***'], array_map(static fn ($e) => $e['payload']['message'], $this->t->events()));
    }

    public function testRequestRecorderAndIgnoredRoutes(): void
    {
        $this->client();
        $rec = Codeskop::startRequest(new IncomingRequest('POST', '/orders/12', ['x-request-id' => 'req-1'], [], '10.0.0.1', null, 42));
        Codeskop::setUser('u9');
        Codeskop::setRoute('orders/{id}');
        Codeskop::client()->captureException(new \RuntimeException('boom'), false, 'test');
        $rec->finish(null, 200, 10);
        Codeskop::startRequest(new IncomingRequest('GET', '/healthz'))->finish(null, 200);
        Codeskop::flush();
        [$exc, $req] = $this->t->events();
        $this->assertSame(['method' => 'POST', 'route' => '/orders/{id}', 'request_id' => 'req-1'], $exc['payload']['request']);
        $this->assertSame('high', $exc['severity']);
        $this->assertSame('/orders/{id}', $req['payload']['route']);
        $this->assertSame(500, $req['payload']['status'], 'a request that raised is recorded as 500');
        $this->assertSame(42, $req['payload']['request_bytes']);
        $this->assertSame('req-1', $req['payload']['request_id']);
        $this->assertSame('u9', $req['user']['id']);
        $this->assertCount(2, $this->t->events());
    }

    public function testForkedChildDropsParentQueue(): void
    {
        $c = $this->client();
        Codeskop::captureMessage('parent');
        $pid = new \ReflectionProperty(Client::class, 'pid');
        $pid->setValue($c, -1); // pretend we were forked
        Codeskop::captureMessage('child');
        Codeskop::flush();
        $this->assertSame(['child'], array_map(static fn ($e) => $e['payload']['message'], $this->t->events()));
    }

    public function testSendUserIdOff(): void
    {
        $this->client(['send_user_id' => false]);
        Codeskop::setUser('u1');
        Codeskop::captureMessage('m');
        Codeskop::flush();
        $this->assertArrayNotHasKey('user', $this->t->events()[0]);
    }
}
