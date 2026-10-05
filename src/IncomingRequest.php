<?php

declare(strict_types=1);

namespace Codeskop;

/** The parts of an incoming request the SDK reads. Build it from globals or from a framework request. */
final class IncomingRequest
{
    /**
     * @param array<string, string> $headers lower-case names
     * @param array<string, mixed> $query
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public string $remoteAddr = '',
        public ?string $clientCert = null,
        public ?int $contentLength = null,
        public ?float $startedAt = null,
        public mixed $native = null,
    ) {
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public static function fromGlobals(?array $server = null): self
    {
        $server ??= $_SERVER;
        $headers = [];
        foreach ($server as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $h) {
            if (isset($server[$k]) && $server[$k] !== '') {
                $headers[$h] = (string) $server[$k];
            }
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        parse_str((string) ($server['QUERY_STRING'] ?? (parse_url($uri, PHP_URL_QUERY) ?: '')), $query);
        return new self(
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
            $headers,
            $query,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            isset($server['SSL_CLIENT_CERT']) && $server['SSL_CLIENT_CERT'] !== '' ? (string) $server['SSL_CLIENT_CERT'] : null,
            isset($server['CONTENT_LENGTH']) && is_numeric($server['CONTENT_LENGTH']) ? (int) $server['CONTENT_LENGTH'] : null,
            isset($server['REQUEST_TIME_FLOAT']) ? (float) $server['REQUEST_TIME_FLOAT'] : null,
        );
    }

    /** From a Symfony HttpFoundation request (Laravel, Symfony). */
    public static function fromSymfony(object $request): self
    {
        $headers = [];
        foreach ($request->headers->all() as $k => $v) {
            $headers[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $cert = $request->server->get('SSL_CLIENT_CERT');
        $len = $request->headers->get('content-length');
        return new self(
            $request->getMethod(),
            $request->getPathInfo(),
            $headers,
            $request->query->all(),
            (string) $request->server->get('REMOTE_ADDR', ''),
            is_string($cert) && $cert !== '' ? $cert : null,
            is_numeric($len) ? (int) $len : null,
            null,
            $request,
        );
    }
}
