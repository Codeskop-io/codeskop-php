<?php

declare(strict_types=1);

namespace Codeskop;

/** Minimal HTTP client: curl when loaded, else PHP streams. Never throws. */
class Transport
{
    public function __construct(private string $userAgent, private string $apiKey)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 2.0): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => $this->userAgent] + $headers;
        try {
            return function_exists('curl_init') ? $this->curl($method, $url, $headers, $body, $timeout) : $this->stream($method, $url, $headers, $body, $timeout);
        } catch (\Throwable) {
            return ['status' => 0, 'headers' => [], 'body' => ''];
        }
    }

    private function curl(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        $resHeaders = [];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $resHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $resHeaders, 'body' => is_string($out) ? $out : ''];
    }

    private function stream(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $lines = ['Connection: close'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '',
            'timeout' => $timeout, 'ignore_errors' => true, 'protocol_version' => 1.1,
        ]]);
        $http_response_header = [];
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        $resHeaders = [];
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
                $resHeaders = [];
            } elseif (count($p = explode(':', $h, 2)) === 2) {
                $resHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            }
        }
        return ['status' => $status, 'headers' => $resHeaders, 'body' => is_string($out) ? $out : ''];
    }
}
