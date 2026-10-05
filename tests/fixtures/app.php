<?php
// A plain PHP app (php -S router) for the end-to-end test.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Codeskop\Codeskop;

Codeskop::init([
    'api_key' => 'cs_test_pk_testtesttest',
    'endpoint' => getenv('INGEST_URL'),
    'cache_dir' => getenv('CACHE_DIR'),
    'transport' => getenv('USE_STREAMS') ? new class ('codeskop-php/' . Codeskop::VERSION, 'cs_test_pk_testtesttest') extends \Codeskop\Transport {
        public function request(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 2.0): array
        {
            $m = new \ReflectionMethod(\Codeskop\Transport::class, 'stream');
            return $m->invoke($this, $method, $url, ['Authorization' => 'Bearer cs_test_pk_testtesttest', 'User-Agent' => 'codeskop-php/' . Codeskop::VERSION] + $headers, $body, $timeout);
        }
    } : null,
]);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/orders/(\d+)$#', $path, $m)) {
    Codeskop::setRoute('/orders/{id}');
    Codeskop::setUser('u-' . $m[1]);
    header('Content-Type: application/json');
    echo json_encode(['id' => (int) $m[1]]);
} elseif ($path === '/boom') {
    throw new UnexpectedValueException('plain php boom');
} elseif ($path === '/fatal') {
    undefined_function_for_codeskop();
} elseif ($path === '/oom') {
    ini_set('memory_limit', '16M');
    $a = [];
    while (true) {
        $a[] = str_repeat('x', 1 << 20);
    }
} else {
    http_response_code(404);
    echo 'nope';
}
