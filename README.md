# Codeskop for PHP

Errors, incoming requests and outgoing API calls from your PHP and Laravel apps, in Codeskop. PHP 8.1+, no runtime dependencies (`ext-curl` recommended, `ext-apcu` optional).

```bash
composer require codeskop/codeskop-php
```

## Laravel

The service provider is auto-discovered. Add your project's **public** key to `.env`:

```dotenv
CODESKOP_API_KEY=cs_live_pk_…
```

That's it: exceptions are reported through Laravel's handler (anything in `dontReport` is skipped), every request is recorded by route template (`/api/orders/{order}`), the HTTP client (`Http::get(…)`) is instrumented, and the signed-in user's ID is attached. To change the defaults:

```bash
php artisan vendor:publish --tag=codeskop-config
```

## Plain PHP

```php
require __DIR__ . '/vendor/autoload.php';

\Codeskop\Codeskop::init(['api_key' => getenv('CODESKOP_API_KEY')]);
\Codeskop\Codeskop::setRoute('/orders/{id}'); // optional: name the route of this request
```

`init()` installs an exception handler (it keeps any previous one) and a shutdown handler for fatal errors, and records the current request. Without `setRoute()`, numeric, UUID and long-hex path segments become `{id}`.

## Errors, users and outgoing calls

```php
use Codeskop\Codeskop;

Codeskop::captureException($e, tags: ['provider' => 'stripe']);
Codeskop::captureMessage('Payout batch skipped');
Codeskop::setUser((string) $user->id); // your own ID, never an email

$stack = \GuzzleHttp\HandlerStack::create();
$stack->push(\Codeskop\Guzzle\Middleware::create());
$http = new \GuzzleHttp\Client(['handler' => $stack]);
```

## How delivery works

PHP shares nothing between requests, so events are kept in memory and sent when the request ends: after the response has gone out under PHP-FPM (`fastcgi_finish_request`), so users never wait on it. Queue workers and Octane flush after each job or request; other long-running CLI scripts send every 100 events and at exit (or call `Codeskop::flush()`). Remote config is cached for 5 minutes in APCu, or in a file in the temp directory.

## Options

`api_key`, `endpoint`, `environment`, `release` (auto-detected from common CI/host variables), `capture_requests`, `capture_outgoing`, `ignore_routes`, `ignore_exceptions`, `before_send`, `send_user_id`, `flush_timeout` (default 2 s), `debug`, `enabled`. Environment variables: `CODESKOP_API_KEY`, `CODESKOP_ENDPOINT`, `CODESKOP_ENVIRONMENT`, `CODESKOP_RELEASE`.

Never captured: request or response bodies, cookies, `Authorization` headers, query strings.

## Development

```bash
docker build -f Dockerfile.test --build-arg PHP=8.3 -t codeskop-php-test . && docker run --rm codeskop-php-test
```

## License

MIT
