<?php

declare(strict_types=1);

namespace Codeskop\Laravel;

use Codeskop\Codeskop;
use Codeskop\Events;
use Codeskop\Guzzle\Middleware as GuzzleMiddleware;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered. Reads config/codeskop.php (publish with `php artisan vendor:publish --tag=codeskop-config`),
 * reports exceptions through Laravel's handler (honouring dontReport), records requests by route
 * template and instruments the HTTP client.
 */
class CodeskopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/codeskop.php', 'codeskop');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/codeskop.php' => $this->app->configPath('codeskop.php')], 'codeskop-config');
        $cfg = (array) $this->app['config']->get('codeskop', []);
        $enabled = filter_var($cfg['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $client = Codeskop::init(array_merge($cfg, [
            'enabled' => $enabled,
            'debug' => filter_var($cfg['debug'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'auto_request' => false,
            'framework' => 'laravel ' . $this->app->version(),
            'project_root' => $this->app->basePath(),
        ]));
        if (!$client->enabled()) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (\Throwable $e): void {
                $rec = Codeskop::client()?->currentRequest();
                if ($rec !== null && $rec->route === null && $this->app->bound('request')) {
                    $route = $this->app['request']->route();
                    if (is_object($route) && method_exists($route, 'uri')) {
                        $rec->route = Events::templateRoute($route->uri());
                    }
                }
                Codeskop::client()?->captureException($e, false, 'laravel');
            });
        }

        if (($cfg['capture_requests'] ?? true) !== false && $this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            $this->app->singleton(CodeskopMiddleware::class);
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(CodeskopMiddleware::class);
            }
        }

        if (($cfg['capture_outgoing'] ?? true) !== false && class_exists(\Illuminate\Http\Client\Factory::class)) {
            $factory = $this->app->make(\Illuminate\Http\Client\Factory::class);
            if (method_exists($factory, 'globalMiddleware')) {
                $factory->globalMiddleware(GuzzleMiddleware::create());
            }
        }

        // Long-running processes: send after each job / Octane request instead of only at exit.
        $events = $this->app['events'];
        $flush = static fn () => Codeskop::flush(2.0);
        foreach (['Illuminate\Queue\Events\JobProcessed', 'Illuminate\Queue\Events\JobFailed', 'Laravel\Octane\Events\RequestTerminated', 'Laravel\Octane\Events\TaskTerminated'] as $event) {
            $events->listen($event, $flush);
        }
    }
}
