<?php

declare(strict_types=1);

namespace Codeskop;

/** Event builders and route helpers (docs/10 §10.6). */
final class Events
{
    public const MAX_MESSAGE = 2048;
    public const MAX_FRAMES = 100;

    public static function nowIso(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000));
    }

    public static function newId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** The server's templating: no query; numeric, UUID and long-hex segments become {id}. */
    public static function normalizePath(?string $path): string
    {
        $path = (string) $path;
        if ($path === '') {
            return '/';
        }
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        $segs = explode('/', $path);
        foreach ($segs as $i => $s) {
            if ($s !== '' && (preg_match('/^\d+$/', $s)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)
                || preg_match('/^[0-9a-f]{16,}$/i', $s))) {
                $segs[$i] = '{id}';
            }
        }
        $out = implode('/', $segs);
        return str_starts_with($out, '/') ? $out : '/' . $out;
    }

    /** Router templates to our form: "orders/{id}", "/users/{user?}", "/files/{path:.*}", "/u/:id". */
    public static function templateRoute(?string $route): string
    {
        $route = trim((string) $route);
        if ($route === '' || $route === '/') {
            return '/';
        }
        $route = preg_replace('/:([A-Za-z0-9_]+)/', '{$1}', $route);
        $route = preg_replace('/\{([A-Za-z0-9_]+)(\?|:[^}]*)?\}/', '{$1}', $route);
        return str_starts_with($route, '/') ? $route : '/' . $route;
    }

    public static function inApp(?string $file, ?string $class = null): bool
    {
        if ($file === null || $file === '' || str_starts_with($file, '[')) {
            return false;
        }
        $f = str_replace('\\', '/', $file);
        if (str_contains($f, '/vendor/')) {
            return false;
        }
        if ($class !== null && str_starts_with($class, 'Codeskop\\') && !str_starts_with($class, 'Codeskop\\Tests\\')) {
            return false;
        }
        return true;
    }

    public static function shortFile(?string $file, ?string $root): ?string
    {
        if ($file === null) {
            return null;
        }
        $f = str_replace('\\', '/', $file);
        if (($i = strpos($f, '/vendor/')) !== false) {
            return substr($f, $i + 1);
        }
        if ($root !== null && $root !== '' && str_starts_with($f, $root . '/')) {
            return substr($f, strlen($root) + 1);
        }
        return $f;
    }

    /** Frames innermost first: the throw site, then each caller. */
    public static function frames(\Throwable $e, ?string $root): array
    {
        $trace = $e->getTrace();
        $out = [];
        $file = $e->getFile();
        $line = $e->getLine();
        foreach ($trace as $i => $t) {
            $class = $t['class'] ?? null;
            $out[] = self::frame($class, $t['function'] ?? null, $file, $line, $root);
            $file = $t['file'] ?? null;
            $line = $t['line'] ?? 0;
            if (count($out) >= self::MAX_FRAMES) {
                return $out;
            }
        }
        $out[] = self::frame(null, '{main}', $file, $line, $root);
        return $out;
    }

    private static function frame(?string $class, ?string $function, ?string $file, int $line, ?string $root): array
    {
        $f = ['class' => $class ?? self::fileModule($file), 'method' => $function ?? '', 'file' => self::shortFile($file, $root) ?? '', 'line' => $line];
        $f['in_app'] = self::inApp($file, $class) && !self::internalFunction($class, $function);
        return $f;
    }

    private static function internalFunction(?string $class, ?string $function): bool
    {
        if ($class !== null || $function === null || !function_exists($function)) {
            return false;
        }
        return (new \ReflectionFunction($function))->isInternal();
    }

    private static function fileModule(?string $file): string
    {
        return $file ? basename($file, '.php') : '';
    }

    public static function exceptionPayload(\Throwable $e, ?string $root, int $depth = 0): array
    {
        $msg = $e->getMessage();
        if (strlen($msg) > self::MAX_MESSAGE) {
            $msg = substr($msg, 0, self::MAX_MESSAGE);
        }
        $p = ['exception_class' => get_class($e), 'message' => $msg, 'stacktrace' => self::frames($e, $root)];
        if (($prev = $e->getPrevious()) !== null && $depth < 3) {
            $p['cause'] = self::exceptionPayload($prev, $root, $depth + 1);
        }
        return $p;
    }

    public static function event(string $type, string $severity, array $payload, ?string $userId = null): array
    {
        $e = ['event_id' => self::newId(), 'type' => $type, 'severity' => $severity, 'occurred_at' => self::nowIso(), 'payload' => $payload];
        if ($userId !== null && $userId !== '') {
            $e['user'] = ['id' => substr($userId, 0, 128)];
        }
        return $e;
    }

    public static function exception(\Throwable $e, bool $handled, string $mechanism, ?array $request, ?string $userId, array $tags, ?string $root): array
    {
        $p = self::exceptionPayload($e, $root);
        $p['handled'] = $handled;
        $p['mechanism'] = $mechanism;
        if ($request) {
            $p['request'] = $request;
        }
        if ($tags) {
            $p['tags'] = array_map('strval', $tags);
        }
        return self::event('exception', $handled ? 'medium' : 'high', $p, $userId);
    }

    public static function request(string $method, string $route, int $status, float $durationMs, ?int $reqBytes, ?int $resBytes, ?string $requestId, bool $failed, ?string $userId, array $extra): array
    {
        $p = ['method' => strtoupper($method), 'route' => $route, 'status' => $status, 'duration_ms' => round($durationMs, 3)];
        if ($reqBytes !== null && $reqBytes >= 0) {
            $p['request_bytes'] = $reqBytes;
        }
        if ($resBytes !== null && $resBytes >= 0) {
            $p['response_bytes'] = $resBytes;
        }
        if ($requestId) {
            $p['request_id'] = $requestId;
        }
        $p += $extra;
        return self::event('http_request', $failed || $status >= 500 ? 'high' : 'low', $p, $userId);
    }

    /** @return array<int, array> api_timing, plus api_error for failures. */
    public static function outgoing(string $method, string $host, string $path, int $status, float $durationMs, ?string $errorKind, ?string $userId, ?int $reqBytes = null, ?int $resBytes = null): array
    {
        $p = ['method' => strtoupper($method), 'host' => $host, 'path' => self::normalizePath($path), 'duration_ms' => round($durationMs, 3)];
        if ($status > 0) {
            $p['status'] = $status;
        }
        if ($reqBytes !== null && $reqBytes >= 0) {
            $p['request_bytes'] = $reqBytes;
        }
        if ($resBytes !== null && $resBytes >= 0) {
            $p['response_bytes'] = $resBytes;
        }
        if (($errorKind === null || $errorKind === '') && $status >= 400) {
            $errorKind = $status >= 500 ? 'http_5xx' : 'http_4xx';
        }
        $out = [self::event('api_timing', 'low', $p, $userId)];
        if ($errorKind) {
            $p['error_kind'] = $errorKind;
            $out[] = self::event('api_error', 'high', $p, $userId);
        }
        return $out;
    }
}
