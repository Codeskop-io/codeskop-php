<?php

declare(strict_types=1);

namespace Codeskop;

/**
 * Queue, remote config, sampling and delivery (docs/10 §10.4–10.5).
 *
 * PHP shares nothing between requests, so events are kept in memory for the request and sent when it
 * ends (after the response under PHP-FPM, via fastcgi_finish_request), or as soon as 100 are queued
 * in long-running workers. Remote config and verdicts are cached across requests (APCu or temp file).
 */
class Client
{
    public const VERSION = '0.1.1';
    public const MAX_BATCH = 100;
    public const MAX_EVENT_BYTES = 65536;
    public const MAX_BODY_BYTES = 1048576;
    public const CONFIG_REFRESH = 300;

    private const DEFAULTS = [
        'api_key' => null,
        'endpoint' => null,
        'environment' => null,
        'release' => null,
        'capture_requests' => true,
        'capture_outgoing' => true,
        'auto_request' => null, // record the plain-PHP request from globals (null: when not CLI)
        'sample_rates' => [],
        'ignore_routes' => ['/health*', '/healthz', '/metrics', '/favicon.ico'],
        'ignore_exceptions' => [],
        'before_send' => null,
        'send_user_id' => true,
        'max_queue_events' => 10000,
        'flush_timeout' => 2.0,
        'debug' => false,
        'enabled' => true,
        'api_trust_resolver' => null,
        'trust_proxy' => null,
        'project_root' => null,
        'framework' => null,
        'cache_dir' => null,
        'transport' => null,
    ];

    /** @var array<string, mixed> */
    private array $opts;
    private bool $enabled = false;
    /** @var list<array> */
    private array $queue = [];
    private ?array $remote = null;
    private bool $configStale = false;
    private ?Trust $trust = null;
    private Transport $transport;
    private Cache $cache;
    private int $pid;
    private ?string $userId = null;
    private ?RequestRecorder $current = null;
    private bool $shutdownRegistered = false;
    private ?string $reserved = null;
    private bool $uncaughtCaptured = false;
    /** @var callable|null */
    private $previousExceptionHandler = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $o = array_merge(self::DEFAULTS, $options);
        $o['api_key'] = trim((string) ($o['api_key'] ?? self::env('CODESKOP_API_KEY')));
        $o['endpoint'] = rtrim((string) ($o['endpoint'] ?: (self::env('CODESKOP_ENDPOINT') ?: 'https://api.codeskop.com')), '/');
        $o['environment'] = (string) ($o['environment'] ?: (self::env('CODESKOP_ENVIRONMENT') ?: 'production'));
        $o['release'] = $o['release'] ?: self::detectRelease();
        if (isset($options['project_root']) === false) {
            $o['project_root'] = self::guessRoot();
        }
        $this->opts = $o;
        $this->pid = (int) getmypid();
        $this->transport = $o['transport'] instanceof Transport ? $o['transport'] : new Transport('codeskop-php/' . self::VERSION, $o['api_key']);
        $this->cache = new Cache(substr(hash('sha256', $o['api_key'] . '|' . $o['endpoint']), 0, 16), $o['cache_dir']);
        $problem = self::keyProblem($o['api_key']);
        if ($problem !== null) {
            error_log('codeskop: disabled: ' . $problem);
        } elseif ($o['enabled'] !== false) {
            $this->enabled = true;
        }
    }

    public static function env(string $k): ?string
    {
        $v = getenv($k);
        if ($v === false) {
            $v = $_ENV[$k] ?? $_SERVER[$k] ?? null;
        }
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private static function detectRelease(): ?string
    {
        foreach (['CODESKOP_RELEASE', 'RENDER_GIT_COMMIT', 'HEROKU_SLUG_COMMIT', 'SOURCE_VERSION', 'RAILWAY_GIT_COMMIT_SHA', 'K_REVISION', 'GITHUB_SHA', 'LARAVEL_CLOUD_COMMIT'] as $k) {
            if (($v = self::env($k)) !== null) {
                return substr($v, 0, 64);
            }
        }
        return null;
    }

    private static function guessRoot(): ?string
    {
        $file = str_replace('\\', '/', __FILE__);
        $i = strpos($file, '/vendor/');
        return $i === false ? null : substr($file, 0, $i);
    }

    public static function keyProblem(string $key): ?string
    {
        if ($key === '') {
            return 'no api_key (set CODESKOP_API_KEY or the api_key option)';
        }
        if (str_contains($key, '_sk_')) {
            return 'a secret key (_sk_) was given; use the project\'s public key (cs_..._pk_...)';
        }
        if (!preg_match('/^cs_(live|test)_pk_[A-Za-z0-9_-]{8,}$/', $key)) {
            return 'the api_key doesn\'t look like a Codeskop public key';
        }
        return null;
    }

    // -- accessors used by integrations ------------------------------------------------

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function option(string $k): mixed
    {
        return $this->opts[$k] ?? null;
    }

    public function setOption(string $k, mixed $v): void
    {
        $this->opts[$k] = $v;
    }

    public function endpoint(): string
    {
        return $this->opts['endpoint'];
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function cache(): Cache
    {
        return $this->cache;
    }

    public function debug(string $msg): void
    {
        if ($this->opts['debug']) {
            error_log('codeskop: ' . $msg);
        }
    }

    public function setUser(?string $id): void
    {
        $this->userId = $id === null || $id === '' ? null : $id;
    }

    public function user(): ?string
    {
        return $this->opts['send_user_id'] === false ? null : $this->userId;
    }

    public function currentRequest(): ?RequestRecorder
    {
        return $this->current;
    }

    public function setCurrentRequest(?RequestRecorder $r): void
    {
        $this->current = $r;
    }

    public function queued(): int
    {
        return count($this->queue);
    }

    // -- remote config & sampling ---------------------------------------------------------

    /** @return array<string, mixed> */
    public function remote(): array
    {
        if ($this->remote !== null) {
            return $this->remote;
        }
        $c = $this->cache->get('config');
        if ($c === null || !is_array($c['config'] ?? null)) {
            $c = $this->fetchConfig($c ?? []);
        } elseif (time() - (int) ($c['at'] ?? 0) > self::CONFIG_REFRESH) {
            $this->configStale = true; // refreshed after the response, at flush time
        }
        $this->remote = is_array($c['config'] ?? null) ? $c['config'] : [];
        $tc = $this->remote['api_trust'] ?? null;
        $this->trust = is_array($tc) && ($tc['enabled'] ?? false) === true ? new Trust($this, $tc) : null;
        return $this->remote;
    }

    /** GET /v1/config with ETag. On failure keeps the last good config. */
    private function fetchConfig(array $prev): array
    {
        $prev['at'] = time();
        $this->cache->set('config', $prev);
        $headers = isset($prev['etag']) && $prev['etag'] !== '' && isset($prev['config']) ? ['If-None-Match' => (string) $prev['etag']] : [];
        $res = $this->transport->request('GET', $this->endpoint() . '/v1/config', $headers, null, 1.5);
        if ($res['status'] === 200) {
            $cfg = json_decode($res['body'], true);
            if (is_array($cfg)) {
                $prev = ['config' => $cfg, 'etag' => $res['headers']['etag'] ?? '', 'at' => time()];
                $this->cache->set('config', $prev);
            }
        } elseif ($res['status'] === 401 || $res['status'] === 403) {
            error_log("codeskop: the API key was refused (HTTP {$res['status']}); events won't be accepted");
        } else {
            $this->debug('config fetch failed (HTTP ' . $res['status'] . ')');
        }
        return $prev;
    }

    public function trust(): ?Trust
    {
        if (!$this->enabled) {
            return null;
        }
        $this->remote();
        return $this->trust;
    }

    private function feature(string $name): bool
    {
        $v = $this->remote()['features'][$name] ?? true;
        return $v !== false;
    }

    public function sampleRate(string $type): float
    {
        $remote = $this->remote()['sample_rates'] ?? [];
        foreach ([is_array($remote) ? $remote : [], (array) $this->opts['sample_rates']] as $src) {
            if (isset($src[$type]) && is_numeric($src[$type])) {
                return max(0.0, min(1.0, (float) $src[$type]));
            }
            if ($type === 'http_request' && isset($src['api_timing']) && is_numeric($src['api_timing'])) {
                return max(0.0, min(1.0, (float) $src['api_timing']));
            }
        }
        return 1.0;
    }

    private function keep(array $e): bool
    {
        $type = $e['type'];
        if ($type === 'exception' || $type === 'api_error' || $e['severity'] === 'high') {
            return true;
        }
        if ($type === 'http_request' && isset($e['payload']['consumer'])) {
            return true; // API Trust audit trail
        }
        $r = $this->sampleRate($type);
        return $r >= 1.0 || mt_rand() / mt_getrandmax() < $r;
    }

    public function ignoredRoute(string $route): bool
    {
        foreach ((array) $this->opts['ignore_routes'] as $glob) {
            if (fnmatch((string) $glob, $route)) {
                return true;
            }
        }
        return false;
    }

    public function ignoredException(\Throwable $e): bool
    {
        foreach ((array) $this->opts['ignore_exceptions'] as $cls) {
            if (is_string($cls) && ($e instanceof $cls || get_class($e) === ltrim($cls, '\\'))) {
                return true;
            }
        }
        return false;
    }

    // -- capture --------------------------------------------------------------------------

    public function capture(array $e): void
    {
        try {
            if (!$this->enabled) {
                return;
            }
            $this->forkCheck();
            if (($this->remote()['enabled'] ?? true) === false) {
                $this->queue = [];
                return;
            }
            if (in_array($e['type'], ['http_request', 'api_timing', 'api_error'], true) && !$this->feature('network')) {
                return;
            }
            if (!$this->keep($e)) {
                return;
            }
            if (is_callable($this->opts['before_send'])) {
                $e = ($this->opts['before_send'])($e);
                if (!is_array($e)) {
                    return;
                }
            }
            if (count($this->queue) >= (int) $this->opts['max_queue_events']) {
                array_shift($this->queue);
            }
            $this->queue[] = $e;
            $this->registerShutdown();
            if (count($this->queue) >= self::MAX_BATCH && PHP_SAPI === 'cli') {
                $this->flush();
            }
        } catch (\Throwable $t) {
            $this->debug('capture failed: ' . $t->getMessage());
        }
    }

    public function captureException(\Throwable $e, bool $handled = true, string $mechanism = 'manual', array $tags = [], ?string $userId = null): void
    {
        if (!$this->enabled || $this->ignoredException($e)) {
            return;
        }
        $request = $this->current?->summary();
        if (!$handled && $this->current !== null) {
            $this->current->markFailed();
        }
        $this->capture(Events::exception($e, $handled, $mechanism, $request, $userId ?? $this->user(), $tags, $this->opts['project_root']));
    }

    public function captureMessage(string $message, string $severity = 'medium'): void
    {
        if (!$this->enabled) {
            return;
        }
        $p = ['exception_class' => 'Message', 'message' => substr($message, 0, Events::MAX_MESSAGE), 'stacktrace' => [], 'handled' => true, 'mechanism' => 'message'];
        $this->capture(Events::event('exception', in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium', $p, $this->user()));
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $app = ['environment' => $this->opts['environment'], 'sdk_name' => 'codeskop-php', 'sdk_version' => self::VERSION];
        if ($this->opts['release']) {
            $app['release'] = (string) $this->opts['release'];
        }
        if ($this->opts['framework']) {
            $app['framework'] = (string) $this->opts['framework'];
        }
        return [
            'device' => ['platform' => 'php', 'hostname' => (string) gethostname(), 'os' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'runtime' => 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')'],
            'app' => $app,
        ];
    }

    // -- sending ----------------------------------------------------------------------------

    private function forkCheck(): void
    {
        $pid = (int) getmypid();
        if ($pid !== $this->pid) { // forked child: never re-send the parent's events
            $this->pid = $pid;
            $this->queue = [];
        }
    }

    /** Sends everything queued; returns whether it all went. */
    public function flush(?float $timeout = null): bool
    {
        if (!$this->enabled) {
            return true;
        }
        try {
            $this->forkCheck();
            if ($this->configStale) {
                $this->configStale = false;
                $c = $this->cache->get('config') ?? [];
                if (time() - (int) ($c['at'] ?? 0) > self::CONFIG_REFRESH) {
                    $this->fetchConfig($c);
                }
            }
            $deadline = microtime(true) + ($timeout ?? (float) $this->opts['flush_timeout']);
            $backoff = $this->cache->get('backoff');
            if ($backoff !== null && (float) ($backoff['until'] ?? 0) > microtime(true)) {
                $this->debug('ingest asked us to back off; dropping ' . count($this->queue) . ' events');
                $this->queue = [];
                return false;
            }
            while ($this->queue) {
                $batch = array_splice($this->queue, 0, self::MAX_BATCH);
                foreach ($this->bodies($batch) as $body) {
                    if (!$this->sendWithRetry($body, $deadline)) {
                        $this->queue = [];
                        return false;
                    }
                }
            }
            return true;
        } catch (\Throwable $t) {
            $this->debug('flush failed: ' . $t->getMessage());
            return false;
        }
    }

    private function sendWithRetry(string $body, float $deadline): bool
    {
        for ($attempt = 0; ; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left <= 0.05) {
                $this->debug('flush timed out; dropping a batch');
                return false;
            }
            $res = $this->transport->request('POST', $this->endpoint() . '/v1/events', ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], $body, min(5.0, $left));
            $s = $res['status'];
            if ($s >= 200 && $s < 300) {
                return true;
            }
            if ($s >= 400 && $s < 500 && $s !== 429) {
                error_log("codeskop: ingest refused a batch (HTTP $s); dropping it");
                return true;
            }
            if ($s === 429 || $s === 503) {
                $wait = is_numeric($res['headers']['retry-after'] ?? null) ? (float) $res['headers']['retry-after'] : 5.0;
            } else {
                $wait = min(60.0, 2 ** $attempt) * (0.8 + mt_rand() / mt_getrandmax() * 0.4);
            }
            if ($wait > $deadline - microtime(true) || $attempt >= 2) {
                // Too long to wait inside this request: remember it so other requests don't hammer ingest.
                $this->cache->set('backoff', ['until' => microtime(true) + $wait]);
                $this->debug("ingest unavailable (HTTP $s); backing off {$wait}s");
                return false;
            }
            usleep((int) ($wait * 1e6));
        }
    }

    /** @return list<string> gzip bodies of at most 1 MB */
    public function bodies(array $batch): array
    {
        $events = [];
        foreach ($batch as $e) {
            $json = json_encode($e, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json !== false && strlen($json) <= self::MAX_EVENT_BYTES) {
                $events[] = $e;
            } else {
                $this->debug('dropped an event over 64 KB');
            }
        }
        return $events ? $this->split($events) : [];
    }

    private function split(array $events): array
    {
        $raw = json_encode(['sent_at' => Events::nowIso(), 'context' => $this->context(), 'batch' => $events], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $gz = (string) gzencode((string) $raw, 6);
        if (strlen($gz) <= self::MAX_BODY_BYTES || count($events) === 1) {
            return [$gz];
        }
        $mid = intdiv(count($events), 2);
        return array_merge($this->split(array_slice($events, 0, $mid)), $this->split(array_slice($events, $mid)));
    }

    // -- process lifecycle --------------------------------------------------------------------

    public function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->reserved = null;
            $this->captureFatal();
            // Run last, after the app's own shutdown functions, so the response is complete.
            register_shutdown_function(function (): void {
                if ($this->current !== null && $this->current->autoFinish) {
                    $this->current->finishFromGlobals();
                }
                if ($this->queue || $this->configStale) {
                    if (function_exists('fastcgi_finish_request') && PHP_SAPI === 'fpm-fcgi') {
                        @fastcgi_finish_request();
                    }
                    $this->flush();
                }
            });
        });
    }

    public function installErrorHandlers(): void
    {
        $this->reserved = str_repeat(' ', 32768); // freed on shutdown so out-of-memory fatals can still be reported
        $this->previousExceptionHandler = set_exception_handler(function (\Throwable $e): void {
            $this->uncaughtCaptured = true;
            $this->captureException($e, false, 'uncaught');
            if ($this->previousExceptionHandler !== null) {
                ($this->previousExceptionHandler)($e);
                return;
            }
            throw $e; // let PHP print/log it as usual
        });
        $this->registerShutdown();
    }

    private function captureFatal(): void
    {
        $err = error_get_last();
        if ($err === null || !($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
            return;
        }
        if ($this->uncaughtCaptured && str_starts_with($err['message'], 'Uncaught ')) {
            return;
        }
        if (str_starts_with($err['message'], 'Allowed memory size')) {
            @ini_set('memory_limit', (string) (memory_get_usage() + 16 * 1024 * 1024)); // room to report it
        }
        $msg = substr(explode("\nStack trace:", $err['message'], 2)[0], 0, Events::MAX_MESSAGE);
        $file = $err['file'] ?? '';
        $p = [
            'exception_class' => 'FatalError', 'message' => $msg, 'handled' => false, 'mechanism' => 'fatal',
            'stacktrace' => [['class' => basename($file, '.php'), 'method' => '', 'file' => (string) Events::shortFile($file, $this->opts['project_root']), 'line' => (int) ($err['line'] ?? 0), 'in_app' => Events::inApp($file)]],
        ];
        if ($this->current !== null) {
            $p['request'] = $this->current->summary();
            $this->current->markFailed();
        }
        $this->capture(Events::event('exception', 'high', $p, $this->user()));
    }
}
