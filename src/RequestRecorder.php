<?php

declare(strict_types=1);

namespace Codeskop;

/** Tracks one incoming request; the plain-PHP auto mode and framework integrations use it. */
final class RequestRecorder
{
    public string $requestId;
    public ?string $route = null;
    public bool $autoFinish = false;
    private float $started;
    private bool $failed = false;
    private bool $blocked = false;
    /** @var array<string, mixed> */
    private array $extra = [];
    private bool $finished = false;

    public function __construct(private Client $client, public IncomingRequest $request)
    {
        $id = $request->header('x-request-id');
        $this->requestId = $id !== '' ? substr($id, 0, 128) : bin2hex(random_bytes(16));
        $this->started = $request->startedAt ?? microtime(true);
    }

    /** Runs API Trust capture; true means the request must be refused with 403 (opt-in blocking, fails open). */
    public function trust(): bool
    {
        try {
            $t = $this->client->trust();
            if ($t === null) {
                return false;
            }
            [$this->extra, $this->blocked] = $t->inspect($this->request);
            return $this->blocked;
        } catch (\Throwable $e) {
            $this->client->debug('api trust failed: ' . $e->getMessage());
            return false;
        }
    }

    public function markFailed(): void
    {
        $this->failed = true;
    }

    /** @return array{method: string, route: string, request_id: string} */
    public function summary(): array
    {
        return ['method' => strtoupper($this->request->method), 'route' => $this->route ?? Events::normalizePath($this->request->path), 'request_id' => $this->requestId];
    }

    public function finish(?string $route, int $status, ?int $responseBytes = null, ?string $userId = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if ($this->client->currentRequest() === $this) {
            $this->client->setCurrentRequest(null);
        }
        try {
            $route ??= $this->route ?? Events::normalizePath($this->request->path);
            if (!$this->client->enabled() || $this->client->option('capture_requests') === false || $this->client->ignoredRoute($route)) {
                return;
            }
            $extra = $this->extra;
            if ($this->blocked) {
                $extra['blocked'] = true;
            }
            if ($this->failed && $status < 500) {
                $status = 500;
            }
            $user = $this->client->option('send_user_id') === false ? null : ($userId ?? $this->client->user());
            $ms = (microtime(true) - $this->started) * 1000;
            $this->client->capture(Events::request($this->request->method, $route, $status, $ms, $this->request->contentLength, $responseBytes, $this->requestId, $this->failed, $user, $extra));
        } catch (\Throwable $e) {
            $this->client->debug('request record failed: ' . $e->getMessage());
        }
    }

    /** Plain PHP: status from http_response_code(), size from Content-Length if the app set one. */
    public function finishFromGlobals(): void
    {
        $status = http_response_code();
        $bytes = null;
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-length:') === 0) {
                $bytes = (int) trim(substr($h, 15));
            }
        }
        $this->finish(null, is_int($status) ? $status : 200, $bytes);
    }
}
