<?php

declare(strict_types=1);

namespace Codeskop\Guzzle;

use Codeskop\Codeskop;
use Codeskop\Events;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware: outgoing calls become api_timing / api_error events.
 *
 *     $stack = HandlerStack::create();
 *     $stack->push(\Codeskop\Guzzle\Middleware::create());
 *     $client = new \GuzzleHttp\Client(['handler' => $stack]);
 */
final class Middleware
{
    public static function create(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $started = microtime(true);
                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($request, $started) {
                        self::record($request, $response->getStatusCode(), $started, null, $response);
                        return $response;
                    },
                    static function ($reason) use ($request, $started) {
                        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                        if ($response instanceof ResponseInterface) {
                            self::record($request, $response->getStatusCode(), $started, null, $response);
                        } else {
                            $kind = $reason instanceof \Throwable && stripos($reason->getMessage(), 'timed out') !== false ? 'timeout' : 'network_error';
                            self::record($request, 0, $started, $kind, null);
                        }
                        return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    private static function record(RequestInterface $request, int $status, float $started, ?string $kind, ?ResponseInterface $response): void
    {
        try {
            $c = Codeskop::client();
            if ($c === null || !$c->enabled() || $c->option('capture_outgoing') === false) {
                return;
            }
            $uri = $request->getUri();
            $endpoint = parse_url($c->endpoint(), PHP_URL_HOST);
            if ($endpoint !== null && strcasecmp((string) $endpoint, $uri->getHost()) === 0) {
                return;
            }
            $reqBytes = $request->getBody()->getSize();
            $resBytes = $response?->getBody()->getSize();
            foreach (Events::outgoing($request->getMethod(), $uri->getHost(), $uri->getPath(), $status, (microtime(true) - $started) * 1000, $kind, $c->user(), $reqBytes, $resBytes) as $e) {
                $c->capture($e);
            }
        } catch (\Throwable) {
        }
    }
}
