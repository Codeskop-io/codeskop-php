<?php
// Mock ingest for the plain-PHP end-to-end test (php -S router). Stores each batch as a file.
$dir = getenv('INGEST_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['HTTP_USER_AGENT'] ?? '') === '' || !str_starts_with($_SERVER['HTTP_USER_AGENT'], 'codeskop-php/')) {
    http_response_code(403);
    exit;
}
header('Content-Type: application/json');
if ($path === '/v1/config') {
    echo json_encode(['enabled' => true, 'features' => ['network' => true], 'sample_rates' => [],
        'api_trust' => ['enabled' => true, 'salt' => 'pepper', 'blocking' => true, 'consumer_sources' => [['type' => 'header', 'name' => 'X-API-Key']]]]);
} elseif ($path === '/v1/trust/verdicts') {
    echo json_encode(['blocked' => [substr(hash_hmac('sha256', 'reseller', 'pepper'), 0, 32)]]);
} elseif ($path === '/v1/events') {
    file_put_contents($dir . '/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.json', gzdecode(file_get_contents('php://input')));
    http_response_code(202);
    echo '{"accepted":1}';
} else {
    http_response_code(404);
}
