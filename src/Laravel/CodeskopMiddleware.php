<?php

declare(strict_types=1);

namespace Codeskop\Laravel;

use Closure;
use Codeskop\Codeskop;
use Codeskop\Events;
use Codeskop\IncomingRequest;
use Codeskop\RequestRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Global middleware (prepended by the service provider): records requests and enforces API Trust blocking. */
class CodeskopMiddleware
{
    private const ATTR = '_codeskop_recorder';

    public function handle(Request $request, Closure $next): mixed
    {
        $rec = Codeskop::startRequest(IncomingRequest::fromSymfony($request));
        $request->attributes->set(self::ATTR, $rec);
        if ($rec->trust()) {
            return response(Codeskop::BLOCKED_BODY, 403, ['Content-Type' => 'application/json']);
        }
        return $next($request);
    }

    /** Runs after the response was sent. */
    public function terminate(Request $request, mixed $response): void
    {
        $rec = $request->attributes->get(self::ATTR);
        if (!$rec instanceof RequestRecorder) {
            return;
        }
        $route = $request->route();
        $template = is_object($route) && method_exists($route, 'uri') ? Events::templateRoute($route->uri()) : null;
        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        $bytes = null;
        if ($response instanceof Response) {
            $len = $response->headers->get('Content-Length');
            $content = $len === null ? $response->getContent() : null;
            $bytes = $len !== null ? (int) $len : (is_string($content) ? strlen($content) : null);
        }
        $rec->finish($template, $status, $bytes, self::userId());
    }

    private static function userId(): ?string
    {
        try {
            $client = Codeskop::client();
            if ($client === null || $client->option('send_user_id') === false) {
                return null;
            }
            if (($explicit = $client->user()) !== null) {
                return $explicit;
            }
            $guard = app('auth')->guard();
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                $id = $guard->id();
                return $id === null ? null : (string) $id;
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
