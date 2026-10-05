<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Codeskop;
use Codeskop\IncomingRequest;
use Codeskop\Trust;

final class TrustTest extends TestCase
{
    private const SALT = 's3cret-salt';

    private function trustConfig(array $over = []): void
    {
        $this->t->config['api_trust'] = $over + [
            'enabled' => true, 'salt' => self::SALT, 'trust_proxy' => true, 'blocking' => false,
            'consumer_sources' => [
                ['type' => 'header', 'name' => 'X-API-Key'],
                ['type' => 'query', 'name' => 'api_key'],
                ['type' => 'jwt', 'header' => 'Authorization', 'claims' => ['client_id', 'sub']],
                ['type' => 'mtls', 'header' => 'X-Client-Cert-Fingerprint'],
            ],
        ];
    }

    private function h(string $raw): string
    {
        return substr(hash_hmac('sha256', $raw, self::SALT), 0, 32);
    }

    private function inspect(IncomingRequest $r): array
    {
        $trust = Codeskop::client()->trust();
        $this->assertInstanceOf(Trust::class, $trust);
        return $trust->inspect($r);
    }

    public function testInactiveWithoutConfig(): void
    {
        $this->client();
        $this->assertNull(Codeskop::client()->trust());
    }

    public function testConsumerSourcesInOrder(): void
    {
        $this->trustConfig();
        $this->client();
        [$extra] = $this->inspect(new IncomingRequest('GET', '/', ['x-api-key' => 'key-123', 'user-agent' => 'curl/8', 'x-forwarded-for' => '203.0.113.7, 10.0.0.1'], ['api_key' => 'other'], '10.0.0.2'));
        $this->assertSame(['id_hash' => $this->h('key-123'), 'auth_type' => 'api_key', 'source' => 'header:X-API-Key'], $extra['consumer']);
        $this->assertSame(['ip' => '203.0.113.7', 'user_agent' => 'curl/8'], $extra['client']);

        [$extra] = $this->inspect(new IncomingRequest('GET', '/', [], ['api_key' => 'q-key']));
        $this->assertSame('query:api_key', $extra['consumer']['source']);
        $this->assertSame($this->h('q-key'), $extra['consumer']['id_hash']);

        $jwt = 'eyJhbGciOiJIUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode(['sub' => 'user-1', 'client_id' => 'partner-9'])), '+/', '-_'), '=') . '.sig';
        [$extra] = $this->inspect(new IncomingRequest('GET', '/', ['authorization' => 'Bearer ' . $jwt]));
        $this->assertSame(['id_hash' => $this->h('partner-9'), 'auth_type' => 'jwt', 'source' => 'jwt'], $extra['consumer']);

        [$extra] = $this->inspect(new IncomingRequest('GET', '/', ['x-client-cert-fingerprint' => 'AB:CD']));
        $this->assertSame('mtls', $extra['consumer']['auth_type']);

        [$extra] = $this->inspect(new IncomingRequest('GET', '/', [], [], '198.51.100.4'));
        $this->assertArrayNotHasKey('consumer', $extra);
        $this->assertSame('198.51.100.4', $extra['client']['ip']);
    }

    public function testMtlsFromClientCertificateAndProxyDistrust(): void
    {
        $this->trustConfig(['trust_proxy' => false]);
        $this->client();
        $der = random_bytes(64);
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64) . "-----END CERTIFICATE-----\n";
        [$extra] = $this->inspect(new IncomingRequest('GET', '/', ['x-forwarded-for' => '1.2.3.4'], [], '10.9.9.9', $pem));
        $this->assertSame($this->h(hash('sha256', $der)), $extra['consumer']['id_hash']);
        $this->assertSame('10.9.9.9', $extra['client']['ip']);
    }

    public function testResolverOverridesSources(): void
    {
        $this->trustConfig();
        $this->client(['api_trust_resolver' => static fn ($r) => 'tenant-' . $r->header('x-tenant')]);
        [$extra] = $this->inspect(new IncomingRequest('GET', '/', ['x-tenant' => '5', 'x-api-key' => 'k']));
        $this->assertSame(['id_hash' => $this->h('tenant-5'), 'auth_type' => 'custom', 'source' => 'resolver'], $extra['consumer']);
    }

    public function testBlockingUsesCachedVerdictsAndFailsOpen(): void
    {
        $this->trustConfig(['blocking' => true]);
        $this->t->verdicts = ['blocked' => [$this->h('bad-key')]];
        $this->client();
        $this->assertTrue($this->inspect(new IncomingRequest('GET', '/', ['x-api-key' => 'bad-key']))[1]);
        $this->assertFalse($this->inspect(new IncomingRequest('GET', '/', ['x-api-key' => 'good-key']))[1]);
        $this->assertSame(1, $this->t->count('/v1/trust/verdicts'), 'verdicts cached for 60 s');

        // Verdict endpoint down and nothing cached: never block.
        array_map('unlink', glob($this->cacheDir . '/*verdicts.json'));
        $this->t->verdictStatus = 500;
        $this->assertFalse($this->inspect(new IncomingRequest('GET', '/', ['x-api-key' => 'bad-key']))[1]);
    }

    public function testBlockedRequestIsRecordedAndAlwaysKept(): void
    {
        $this->trustConfig(['blocking' => true]);
        $this->t->config['sample_rates'] = ['http_request' => 0];
        $this->t->verdicts = ['blocked' => [$this->h('bad-key')]];
        $this->client();
        $rec = Codeskop::startRequest(new IncomingRequest('GET', '/v1/pay/7', ['x-api-key' => 'bad-key']));
        $this->assertTrue($rec->trust());
        $rec->finish(null, 403, 28);
        Codeskop::flush();
        $p = $this->t->events('http_request')[0]['payload'];
        $this->assertTrue($p['blocked']);
        $this->assertSame(403, $p['status']);
        $this->assertSame('/v1/pay/{id}', $p['route']);
        $this->assertSame($this->h('bad-key'), $p['consumer']['id_hash']);
        $this->assertStringNotContainsString('bad-key', json_encode($this->t->envelopes()), 'raw credential never leaves the server');
    }
}
