<?php

declare(strict_types=1);

/*
 * Router for the loopback servers in RedirectLoopback.
 *
 * Records every request it receives (one JSON line each) and answers from a
 * rules file the test rewrites between calls, so a test can say "this path
 * answers 307 with that Location" without restarting the server.
 */

$log = (string) getenv('REDIRECT_LOOPBACK_LOG');
$config = (string) getenv('REDIRECT_LOOPBACK_CONFIG');

$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

$rules = is_file($config) ? json_decode((string) file_get_contents($config), true) : null;
$rules = is_array($rules) ? $rules : [];

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

foreach ($rules['redirects'] ?? [] as $rule) {
    if (str_contains($path, (string) $rule['match'])) {
        http_response_code((int) $rule['status']);
        header('Location: '.str_replace('{host}', (string) ($_SERVER['HTTP_HOST'] ?? ''), (string) $rule['location']));

        return true;
    }
}

http_response_code(200);
header('Content-Type: '.($rules['contentType'] ?? 'application/json'));
echo $rules['body'] ?? '{}';

return true;
