<?php

declare(strict_types=1);

namespace Codeskop;

/**
 * Codeskop server SDK for PHP (docs/10). Every method is safe to call before init() and never throws.
 *
 *     \Codeskop\Codeskop::init(['api_key' => getenv('CODESKOP_API_KEY')]);
 */
final class Codeskop
{
    public const VERSION = Client::VERSION;

    /** JSON body sent to consumers blocked by API Trust. */
    public const BLOCKED_BODY = '{"error":"consumer_blocked"}';

    private static ?Client $client = null;

    /**
     * Starts the SDK. A second call replaces the client. Outside the CLI (and without a framework
     * integration) it also records the current request and, when API Trust blocking is on, answers
     * blocked consumers with 403 before your code runs.
     *
     * @param array<string, mixed> $options see README
     */
    public static function init(array $options = []): Client
    {
        $old = self::$client;
        $c = new Client($options);
        self::$client = $c;
        try {
            $old?->flush(0.5);
            if (!$c->enabled()) {
                return $c;
            }
            $c->installErrorHandlers();
            $auto = $options['auto_request'] ?? (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg');
            if ($auto && isset($_SERVER['REQUEST_METHOD'])) {
                $rec = self::startRequest(IncomingRequest::fromGlobals());
                $rec->autoFinish = true;
                if ($rec->trust()) {
                    self::respondBlocked();
                    $rec->finish(null, 403, strlen(self::BLOCKED_BODY));
                    exit;
                }
            }
        } catch (\Throwable $e) {
            $c->debug('init failed: ' . $e->getMessage());
        }
        return $c;
    }

    private static function respondBlocked(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
        }
        echo self::BLOCKED_BODY;
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    /** Begins recording a request (framework integrations call this); resets the per-request user. */
    public static function startRequest(IncomingRequest $request): RequestRecorder
    {
        $c = self::$client ?? new Client(['enabled' => false]);
        $c->setUser(null);
        $rec = new RequestRecorder($c, $request);
        $c->setCurrentRequest($rec);
        return $rec;
    }

    /** Names the route template of the current plain-PHP request, e.g. "/orders/{id}". */
    public static function setRoute(string $route): void
    {
        if (($r = self::$client?->currentRequest()) !== null) {
            $r->route = Events::templateRoute($route);
        }
    }

    /** @param array<string, scalar> $tags */
    public static function captureException(\Throwable $e, ?string $userId = null, array $tags = []): void
    {
        self::$client?->captureException($e, true, 'manual', $tags, $userId);
    }

    public static function captureMessage(string $message, string $severity = 'medium'): void
    {
        self::$client?->captureMessage($message, $severity);
    }

    /** Attributes the current request's events to your user ID (never an email). */
    public static function setUser(?string $id): void
    {
        self::$client?->setUser($id);
    }

    public static function flush(float $timeout = 2.0): bool
    {
        return self::$client?->flush($timeout) ?? true;
    }

    public static function close(float $timeout = 2.0): void
    {
        self::$client?->flush($timeout);
    }
}
