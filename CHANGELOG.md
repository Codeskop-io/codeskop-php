# Changelog

## 0.1.1 (2026-10-06)

- Contact email is now support@codeskop.com.

## 0.1.0 (2026-10-05)

- First beta: uncaught exceptions and fatal errors (including out-of-memory), handled errors and messages.
- Incoming requests by route template: plain PHP (automatic) and Laravel (global middleware).
- Laravel service provider: exceptions through the app's handler (honours `dontReport`), authenticated user ID, HTTP client instrumentation, flushing after queue jobs and Octane requests.
- Guzzle middleware for outgoing calls.
- Remote config and sampling, cached across requests (APCu or temp file); events sent after the response under PHP-FPM.
- API Trust support (add-on).
