<?php

declare(strict_types=1);

namespace Codeskop\Tests\Support;

use Codeskop\Transport;
use PHPUnit\Framework\Assert;

/** Records requests and answers from a script; also validates envelopes like apps/ingest/schemas.py. */
final class FakeTransport extends Transport
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];
    /** @var list<array> scripted responses for POST /v1/events (default 202) */
    public array $eventResponses = [];
    public array $config = ['enabled' => true, 'features' => ['network' => true], 'sample_rates' => []];
    public ?array $verdicts = null;
    public int $verdictStatus = 200;

    public function __construct()
    {
        parent::__construct('codeskop-php/test', 'cs_test_pk_testtesttest');
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 2.0): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '/v1/config') {
            return ['status' => 200, 'headers' => ['etag' => '"c1"'], 'body' => json_encode($this->config)];
        }
        if ($path === '/v1/trust/verdicts') {
            return ['status' => $this->verdictStatus, 'headers' => ['etag' => '"v1"'], 'body' => json_encode($this->verdicts ?? ['blocked' => []])];
        }
        if ($path === '/v1/events') {
            self::validate($body);
            return array_shift($this->eventResponses) ?? ['status' => 202, 'headers' => [], 'body' => '{"accepted":1}'];
        }
        return ['status' => 404, 'headers' => [], 'body' => ''];
    }

    /** @return list<array> decoded envelopes of POST /v1/events */
    public function envelopes(): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if (str_ends_with($r['url'], '/v1/events')) {
                $out[] = json_decode((string) gzdecode((string) $r['body']), true);
            }
        }
        return $out;
    }

    /** @return list<array> every event sent */
    public function events(?string $type = null): array
    {
        $all = [];
        foreach ($this->envelopes() as $env) {
            foreach ($env['batch'] as $e) {
                if ($type === null || $e['type'] === $type) {
                    $all[] = $e;
                }
            }
        }
        return $all;
    }

    public function count(string $path): int
    {
        return count(array_filter($this->requests, static fn ($r) => str_ends_with($r['url'], $path)));
    }

    public static function validate(?string $body): void
    {
        $raw = gzdecode((string) $body);
        Assert::assertNotFalse($raw, 'body must be gzip');
        Assert::assertLessThanOrEqual(1048576, strlen((string) $body));
        $env = json_decode((string) $raw, true);
        Assert::assertIsArray($env);
        Assert::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $env['sent_at']);
        Assert::assertIsArray($env['context']['device']);
        Assert::assertIsArray($env['context']['app']);
        Assert::assertSame('php', $env['context']['device']['platform']);
        Assert::assertLessThanOrEqual(100, count($env['batch']));
        foreach ($env['batch'] as $e) {
            Assert::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $e['event_id']);
            Assert::assertContains($e['type'], ['api_error', 'api_timing', 'exception', 'http_request']);
            Assert::assertContains($e['severity'], ['low', 'medium', 'high', 'critical']);
            Assert::assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $e['occurred_at']));
            Assert::assertIsArray($e['payload']);
        }
    }
}
