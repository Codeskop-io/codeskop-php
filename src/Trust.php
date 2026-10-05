<?php

declare(strict_types=1);

namespace Codeskop;

/**
 * API Trust capture (docs/10 §10.9), active only when remote config contains api_trust.
 * Consumer credentials are HMAC-hashed here; raw values never leave your server.
 * Blocking is opt-in and fails open.
 */
final class Trust
{
    private const VERDICT_REFRESH = 60;

    /** @param array<string, mixed> $cfg */
    public function __construct(private Client $client, private array $cfg)
    {
    }

    public function active(): bool
    {
        return ($this->cfg['enabled'] ?? false) === true && is_string($this->cfg['salt'] ?? null) && $this->cfg['salt'] !== '';
    }

    public function hash(string $raw): string
    {
        return substr(hash_hmac('sha256', $raw, (string) $this->cfg['salt']), 0, 32);
    }

    /** @return array{id_hash: string, auth_type: string, source: string}|null */
    public function consumer(IncomingRequest $r): ?array
    {
        $resolver = $this->client->option('api_trust_resolver');
        if (is_callable($resolver)) {
            $raw = $resolver($r->native ?? $r);
            if (is_string($raw) && $raw !== '') {
                return ['id_hash' => $this->hash($raw), 'auth_type' => 'custom', 'source' => 'resolver'];
            }
        }
        foreach ((array) ($this->cfg['consumer_sources'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            $raw = '';
            $auth = 'api_key';
            $label = $type;
            switch ($type) {
                case 'header':
                    $name = (string) ($s['name'] ?? '');
                    $raw = $r->header($name);
                    $scheme = (string) ($s['scheme'] ?? '');
                    if ($scheme !== '' && $raw !== '') {
                        $raw = stripos($raw, $scheme . ' ') === 0 ? substr($raw, strlen($scheme) + 1) : '';
                    }
                    $label = 'header:' . $name;
                    break;
                case 'query':
                    $name = (string) ($s['name'] ?? '');
                    $v = $r->query[$name] ?? '';
                    $raw = is_string($v) ? $v : '';
                    $label = 'query:' . $name;
                    break;
                case 'jwt':
                    $claims = array_values(array_filter((array) ($s['claims'] ?? ['sub']), 'is_string')) ?: ['sub'];
                    $raw = self::jwtClaim($r->header((string) ($s['header'] ?? 'Authorization') ?: 'Authorization'), $claims);
                    $auth = $label = 'jwt';
                    break;
                case 'mtls':
                    $header = (string) ($s['header'] ?? '');
                    $raw = $header !== '' ? $r->header($header) : '';
                    if ($raw === '' && $r->clientCert !== null) {
                        $raw = self::certFingerprint($r->clientCert);
                    }
                    $auth = 'mtls';
                    $label = 'mtls:' . $header;
                    break;
            }
            $raw = trim($raw);
            if ($raw !== '') {
                return ['id_hash' => $this->hash($raw), 'auth_type' => $auth, 'source' => substr($label, 0, 80)];
            }
        }
        return null;
    }

    /** @param list<string> $claims */
    public static function jwtClaim(string $header, array $claims): string
    {
        $token = stripos($header, 'bearer ') === 0 ? substr($header, 7) : $header;
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return '';
        }
        $json = base64_decode(strtr(rtrim($parts[1], '='), '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload)) {
            return '';
        }
        foreach ($claims as $c) {
            $v = $payload[$c] ?? null;
            if ($v !== null && $v !== '') {
                return is_string($v) ? $v : (string) json_encode($v);
            }
        }
        return '';
    }

    private static function certFingerprint(string $pem): string
    {
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $b64, true);
        return $der === false ? '' : hash('sha256', $der);
    }

    public function clientIp(IncomingRequest $r): string
    {
        $trustProxy = $this->client->option('trust_proxy') ?? (($this->cfg['trust_proxy'] ?? true) !== false);
        if ($trustProxy) {
            if (($f = $r->header('x-forwarded-for')) !== '') {
                return trim(explode(',', $f)[0]);
            }
            if (($f = $r->header('forwarded')) !== '' && preg_match('/for="?\[?([^\]";,]+)/i', $f, $m)) {
                return $m[1];
            }
            if (($f = $r->header('x-real-ip')) !== '') {
                return trim($f);
            }
        }
        return $r->remoteAddr;
    }

    /** @return array{0: array<string, mixed>, 1: bool} extra payload fields, and whether to block. */
    public function inspect(IncomingRequest $r): array
    {
        if (!$this->active()) {
            return [[], false];
        }
        $extra = [];
        $consumer = $this->consumer($r);
        if ($consumer !== null) {
            $extra['consumer'] = $consumer;
        }
        $client = [];
        foreach ([
            'ip' => [$this->clientIp($r), 64], 'user_agent' => [$r->header('user-agent'), 300],
            'origin' => [$r->header('origin'), 300], 'referer' => [$r->header('referer'), 300],
            'requested_with' => [$r->header('x-requested-with'), 200],
        ] as $k => [$v, $max]) {
            if ($v !== '') {
                $client[$k] = substr($v, 0, $max);
            }
        }
        if ($client) {
            $extra['client'] = $client;
        }
        return [$extra, $consumer !== null && $this->isBlocked($consumer['id_hash'])];
    }

    public function isBlocked(string $hash): bool
    {
        if (($this->cfg['blocking'] ?? false) !== true) {
            return false;
        }
        $cache = $this->client->cache();
        $v = $cache->get('verdicts') ?? [];
        if (time() - (int) ($v['at'] ?? 0) > self::VERDICT_REFRESH) {
            $v = $this->fetch($v);
        }
        return in_array($hash, (array) ($v['blocked'] ?? []), true);
    }

    /** Refreshes the blocked list; on any failure keeps the last list (or none: fail open). */
    private function fetch(array $prev): array
    {
        $cache = $this->client->cache();
        $prev['at'] = time(); // claim the refresh so concurrent workers don't stampede
        $cache->set('verdicts', $prev);
        $headers = isset($prev['etag']) && $prev['etag'] !== '' ? ['If-None-Match' => (string) $prev['etag']] : [];
        $path = (string) ($this->cfg['verdicts_path'] ?? '/v1/trust/verdicts');
        $res = $this->client->transport()->request('GET', $this->client->endpoint() . $path, $headers, null, 1.0);
        if ($res['status'] === 200) {
            $body = json_decode($res['body'], true);
            if (is_array($body) && is_array($body['blocked'] ?? null)) {
                $prev = ['blocked' => array_values(array_filter($body['blocked'], 'is_string')), 'etag' => $res['headers']['etag'] ?? '', 'at' => time()];
                $cache->set('verdicts', $prev);
            }
        }
        return $prev;
    }
}
