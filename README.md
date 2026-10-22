<p align="center">
  <a href="https://www.codeskop.com/?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php">
    <img src="https://www.codeskop.com/media/product-tour-poster.jpg" alt="Codeskop dashboard: crash-free users, active users, open issues and API latency" width="720">
  </a>
</p>

# Codeskop for PHP

**Codeskop** is crash, error and API monitoring for mobile, web and backend apps. [Get started free at www.codeskop.com](https://www.codeskop.com/?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php).

[![Packagist version](https://img.shields.io/packagist/v/codeskop/codeskop-php.svg)](https://packagist.org/packages/codeskop/codeskop-php)
[![PHP version](https://img.shields.io/packagist/dependency-v/codeskop/codeskop-php/php.svg)](https://packagist.org/packages/codeskop/codeskop-php)
[![license](https://img.shields.io/github/license/Codeskop-io/codeskop-php.svg)](https://github.com/Codeskop-io/codeskop-php/blob/main/LICENSE)

> **Beta.** The PHP SDK is in beta, so its API and options may still change between 0.x releases. Feedback is welcome at support@codeskop.com.

`codeskop/codeskop-php` is the PHP SDK for [Codeskop](https://www.codeskop.com/?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php). Add it to a Laravel or plain PHP app and Codeskop groups its errors into issues with stack traces, records every request it handles by route, and shows which outgoing API calls fail or slow down, next to the crashes and network calls from your mobile and web apps.

- **Errors:** uncaught exceptions, fatal errors (including out-of-memory), and `Codeskop::captureException()` / `captureMessage()`. In Laravel, through the app's exception handler.
- **Incoming requests:** every request your app handles, by route (`/api/orders/{order}`), with status, latency and size.
- **Outgoing calls:** Laravel's HTTP client and Guzzle, with status, latency and failures.
- **Light and safe:** no runtime dependencies, and nothing is sent until your response has gone out, so your users never wait on Codeskop.

Works with Laravel 10 and later (including Octane and queue workers), plain PHP and Guzzle 7. PHP 8.1 and later; `ext-curl` recommended, `ext-apcu` optional.

## Get started in 2 minutes

**1. Create a free account** at **[dashboard.codeskop.com/signup](https://dashboard.codeskop.com/signup?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php)**. No credit card. Every project has a **Test** key (`cs_test_pk_…`) and a **Live** key (`cs_live_pk_…`); copy one from **API tokens**.

**2. Install:**

```bash
composer require codeskop/codeskop-php
```

**3. In Laravel, add your key to `.env`** (Test key locally, Live key in production). The service provider is discovered automatically, so that's all:

```dotenv
CODESKOP_API_KEY=cs_test_pk_…
```

**Or, in plain PHP, initialize at the top of your front controller:**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

\Codeskop\Codeskop::init();   // reads CODESKOP_API_KEY; or init(['api_key' => 'cs_live_pk_…'])
```

Throw an exception and it shows up in your dashboard a few seconds later.

## Frameworks

### Laravel

With `CODESKOP_API_KEY` set, Codeskop:

- reports exceptions through Laravel's handler, skipping anything in `dontReport` (404s, validation errors);
- records every request by route (`/api/orders/{order}`) with the signed-in user's ID;
- records calls made with the HTTP client (`Http::get(…)`);
- sends events after each queue job and Octane request.

To change the defaults, publish the config file (`config/codeskop.php`):

```bash
php artisan vendor:publish --tag=codeskop-config
```

### Plain PHP

`init()` reports uncaught exceptions (keeping any handler you already have) and fatal errors including out-of-memory, and records the current request. Without a route, numeric, UUID and long-hex path segments become `{id}`. To name the route yourself:

```php
\Codeskop\Codeskop::setRoute('/orders/{id}');
```

### Guzzle

```php
$stack = \GuzzleHttp\HandlerStack::create();
$stack->push(\Codeskop\Guzzle\Middleware::create());

$http = new \GuzzleHttp\Client(['handler' => $stack]);
```

## Common tasks

```php
use Codeskop\Codeskop;

// Report a handled error, with optional tags.
try {
    $payments->charge($order);
} catch (PaymentException $e) {
    Codeskop::captureException($e, tags: ['provider' => 'stripe']);
}

Codeskop::captureMessage('Inventory sync skipped');

// Attach events to a user (your own ID, never an email).
Codeskop::setUser((string) $user->id);
```

### How events are sent

PHP shares nothing between requests, so events are kept in memory and sent when the request ends. Under PHP-FPM that happens after the response is complete (`fastcgi_finish_request`), so your users never wait on Codeskop. Queue workers and Octane send after each job or request; other long-running CLI scripts send every 100 events and at exit, or call `Codeskop::flush()`. Remote settings are cached between requests in APCu when it's installed, otherwise in a small file in the system temp directory.

## What's captured automatically

- **Uncaught exceptions and fatal errors**, including out-of-memory (in Laravel, through the exception handler, honouring `dontReport`).
- **Every incoming request** by route, with method, status, latency and size. Find them on the Network page under *Requests your servers receive*. Health checks aren't recorded.
- **Outgoing calls** made with Laravel's HTTP client, or Guzzle with the middleware, on the Network page under *APIs your apps call*.
- **Users**: the signed-in Laravel user's ID.
- **Release**, detected from `CODESKOP_RELEASE` or the commit on Render, Heroku, Railway, Cloud Run, Laravel Cloud and GitHub Actions.

## API keys and environments

Server SDKs use the project's **public** key, the same kind of key your mobile and web apps use. Public keys can only send events, never read your data.

| Key | Use it for |
|---|---|
| `cs_test_pk_…` (Test) | Local development, CI and staging, so test traffic never mixes with real users |
| `cs_live_pk_…` (Live) | Production |

Set it as `CODESKOP_API_KEY` in each environment and the SDK reads it on its own, or pass `Codeskop::init(['api_key' => …])` in code. Never put a secret key (`cs_…_sk_…`) in a service: the SDK refuses it and stays switched off, without throwing. A missing or malformed key does the same, so if nothing shows up, check the key first. In Laravel, the `environment` label defaults to `APP_ENV`.

Create a separate Codeskop project for each backend service so its issues and routes stay apart. More: [Core concepts](https://www.codeskop.com/docs/concepts?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) and [server SDK keys](https://www.codeskop.com/docs/server?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php#keys).

## Configuration

In Laravel, set these in `config/codeskop.php` (after publishing it); in plain PHP, pass them to `Codeskop::init([...])`.

| Option | Type | Default | Description |
|---|---|---|---|
| `api_key` | `string` | `CODESKOP_API_KEY` | The project's public key. Secret keys are refused and the SDK stays off. |
| `environment` | `string` | `production` | Shown on events. Laravel uses `APP_ENV`. Env var `CODESKOP_ENVIRONMENT`. |
| `release` | `string` | auto | Your version or commit. Env var `CODESKOP_RELEASE`. |
| `capture_requests` | `bool` | `true` | Record incoming requests. |
| `capture_outgoing` | `bool` | `true` | Record outgoing calls. |
| `ignore_routes` | `array` | health checks | Glob patterns never recorded. |
| `ignore_exceptions` | `array` | `[]` | Exception classes never sent. |
| `before_send` | `callable` | `null` | Edit or drop any event before it's sent. |
| `send_user_id` | `bool` | `true` | Attach the signed-in user's ID. |
| `flush_timeout` | `float` | `2.0` | Seconds to spend sending at the end of a request. |
| `debug` | `bool` | `false` | Log what the SDK does (to `error_log`). |

## Plans

| | Free | Starter | Growth | Scale |
|---|---|---|---|---|
| Errors | ✓ | ✓ | ✓ | ✓ |
| Incoming requests and outgoing API calls | | ✓ | ✓ | ✓ |
| Events per month | 10,000 | 100,000 | 1,000,000 | 10,000,000 |
| Data retention | 7 days | 30 days | 90 days | 365 days |

The SDK is the same on every plan; your plan decides what's kept. Request and API call monitoring follow the network monitoring on your plan, with the same sampling, and failed requests are always kept. See [pricing](https://www.codeskop.com/pricing?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php).

## Privacy

**Never captured:** request or response bodies, cookies, `Authorization` headers and query strings. Requests are recorded by route template, so IDs in the path don't become separate routes.

**What is sent:** the error type, message and stack trace; for each request, the method, route, status, latency and size; for outgoing calls, the method, host, path, status and latency. Each event also carries your environment and release, plus the host name, OS and runtime version of the server.

**You choose the user ID.** Pass your own ID, never an email address or phone number.

**Error messages are sent as written.** If yours can contain personal data, scrub or drop events with `before_send`, or skip error types with `ignore_exceptions`.

Details: [Privacy & Security](https://www.codeskop.com/docs/privacy?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php).

## Documentation

- [PHP guide](https://www.codeskop.com/docs/server/php?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php)
- [Server SDKs overview](https://www.codeskop.com/docs/server?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php)
- [Privacy & Security](https://www.codeskop.com/docs/privacy?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php)
- [Troubleshooting](https://www.codeskop.com/docs/troubleshooting?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php)
- [Changelog](https://github.com/Codeskop-io/codeskop-php/blob/main/CHANGELOG.md)

## Other Codeskop SDKs

Monitor your mobile apps, web apps and backend services in one dashboard:

| Platform | Package | Links |
|---|---|---|
| Android | `com.codeskop.sdk:tracker-android` | [Docs](https://www.codeskop.com/docs/quickstart/android?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) |
| Kotlin Multiplatform and iOS (beta) | `com.codeskop.sdk:tracker-core` | [Docs](https://www.codeskop.com/docs/kmp?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) |
| Flutter | `codeskop_tracker` | [Docs](https://www.codeskop.com/docs/android/flutter?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) |
| Web (JavaScript, React) | `@codeskop/tracker`, `@codeskop/tracker-react` | [Docs](https://www.codeskop.com/docs/web/installation?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/web-sdk) |
| Go | `github.com/Codeskop-io/codeskop-go` | [Docs](https://www.codeskop.com/docs/server/go?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/codeskop-go) |
| Python | `codeskop` | [Docs](https://www.codeskop.com/docs/server/python?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/codeskop-python) |
| Node.js | `@codeskop/node` | [Docs](https://www.codeskop.com/docs/server/node?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/codeskop-node) |
| Java and Kotlin (JVM) | `com.codeskop.sdk:codeskop-server` | [Docs](https://www.codeskop.com/docs/server/java?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/codeskop-jvm) |
| **PHP (beta)** (this SDK) | `codeskop/codeskop-php` | [Docs](https://www.codeskop.com/docs/server/php?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [GitHub](https://github.com/Codeskop-io/codeskop-php) |
| Any other language | OpenTelemetry (OTLP) or HTTPS | [OpenTelemetry](https://www.codeskop.com/docs/server/opentelemetry?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) · [Ingest API](https://www.codeskop.com/docs/reference/ingest-api?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php) |

## Help

Questions or a bug? [Contact us](https://www.codeskop.com/contact?utm_source=github&utm_medium=readme&utm_campaign=codeskop-php), email support@codeskop.com, or open an issue in this repository.

## Development

```bash
docker build -f Dockerfile.test --build-arg PHP=8.3 -t codeskop-php-test . && docker run --rm codeskop-php-test
```

## License

[MIT](https://github.com/Codeskop-io/codeskop-php/blob/main/LICENSE)
