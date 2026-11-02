<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server.
 *
 * The suite starts it with `php -S 127.0.0.1:<port> tests/server.php` and then
 * drives it over real HTTP, so the transport is exercised end to end. Routes
 * whose behaviour spans several requests keep their counter in a state
 * directory passed through `FINCODE_TEST_STATE`.
 */

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rawBody = (string) file_get_contents('php://input');

$echo = [
    'method' => $method,
    'path' => $path,
    'query' => $_GET,
    'auth' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
    'body' => $rawBody === '' ? null : json_decode($rawBody, true),
];

function stateFile(string $name): string
{
    $directory = getenv('FINCODE_TEST_STATE') ?: sys_get_temp_dir();

    return $directory . '/hits-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
}

function peek(string $name): int
{
    $file = stateFile($name);

    return is_file($file) ? (int) file_get_contents($file) : 0;
}

function hit(string $name): int
{
    $count = peek($name) + 1;
    file_put_contents(stateFile($name), (string) $count);

    return $count;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, string> $headers
 */
function respond(int $status, array $payload, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string, mixed> $echo
 */
function ok(mixed $data, ?string $nextCursor = null, array $echo = []): void
{
    respond(200, [
        'data' => $data,
        'meta' => [
            'request_id' => 'req_ok',
            'next_cursor' => $nextCursor,
            'echo' => $echo,
        ],
    ]);
}

/**
 * @param array<string, string> $headers
 */
function fail(int $status, string $code, string $message, array $headers = []): void
{
    respond($status, [
        'error' => ['code' => $code, 'message' => $message, 'request_id' => 'req_err'],
    ], $headers);
}

$swift = [
    'swift_code' => 'ICBKCNBJ',
    'bic' => 'ICBKCNBJ',
    'format' => [
        'institution_code' => 'ICBK',
        'country_code' => 'CN',
        'location_code' => 'BJ',
        'branch_code' => null,
    ],
    'bank' => [
        'id' => 'inst_icbc',
        'name_en' => 'Industrial and Commercial Bank of China',
        'name_local' => '中国工商银行',
        'short_name' => 'ICBC',
        'country' => 'CN',
        'website' => 'https://www.icbc.com.cn',
    ],
    'city' => 'Beijing',
    'status' => 'active',
    'source' => ['name' => 'SWIFT', 'source_type' => 'registry', 'retrieved_at' => '2026-01-01T00:00:00Z'],
];

switch (true) {
    case $path === '/v1/__hits':
        ok(['count' => peek((string) ($_GET['name'] ?? ''))]);
        break;

    case $path === '/v1/echo':
        respond(201, ['data' => ['echo' => $echo], 'meta' => ['request_id' => 'req_echo']]);
        break;

    case $path === '/v1/swift/ICBKCNBJ':
        ok($swift, null, $echo);
        break;

    case $path === '/v1/swift/FLAKY':
        if (hit('flaky') === 1) {
            fail(429, 'RATE_LIMITED', 'Too many requests', ['Retry-After' => '0.01']);
        } else {
            ok($swift, null, $echo);
        }
        break;

    case $path === '/v1/swift/BROKEN':
        hit('broken');
        fail(503, 'UNAVAILABLE', 'Try again later');
        break;

    case $path === '/v1/swift/LIMITED':
        fail(429, 'RATE_LIMITED', 'Slow down', ['Retry-After' => '7']);
        break;

    case $path === '/v1/swift/NOPE':
        fail(422, 'INVALID_SWIFT', 'Not a valid BIC');
        break;

    case $path === '/v1/swift/SLOW':
        usleep(300_000);
        ok($swift, null, $echo);
        break;

    case $path === '/v1/banks':
        if (($_GET['cursor'] ?? null) === 'next') {
            ok([['id' => 'inst_2', 'name_en' => 'Second Bank']], null, $echo);
        } else {
            ok([['id' => 'inst_1', 'name_en' => 'First Bank']], 'next', $echo);
        }
        break;

    case $path === '/v1/countries/US/iban-format':
        ok([
            'country_code' => 'US',
            'iban_length' => 0,
            'structure' => 'not supported',
            'bank_identifier_position' => ['start' => 0, 'length' => 0],
            'account_number_position' => ['start' => 0, 'length' => 0],
            'example' => '',
        ], null, $echo);
        break;

    case $path === '/v1/lookup':
        ok([
            'query' => (string) ($_GET['q'] ?? ''),
            'detected_type' => 'swift',
            'resolved' => true,
            'identifier' => ['type' => 'swift', 'value' => 'ICBKCNBJ', 'bank' => null],
            'suggestions' => [],
        ], null, $echo);
        break;

    case $path === '/v1/iban/validate':
        ok([
            'valid' => true,
            'iban' => (string) ($_GET['iban'] ?? ''),
            'country' => 'DE',
            'check_digits' => '89',
            'bank_code' => '37040044',
            'account_number' => '0532013000',
            'length' => 22,
            'expected_length' => 22,
            'checks' => [
                'charset' => true,
                'country' => true,
                'length' => true,
                'checksum' => true,
                'structure' => true,
            ],
        ], null, $echo);
        break;

    case str_starts_with($path, '/v1/iban/'):
        ok([
            'valid' => true,
            'iban' => substr($path, strlen('/v1/iban/')),
            'country' => 'DE',
            'length' => 22,
            'expected_length' => 22,
            'bank' => [
                'swift_code' => 'COBADEFFXXX',
                'bic' => 'COBADEFFXXX',
                'bank' => ['id' => 'inst_commerzbank', 'name_en' => 'Commerzbank'],
                'city' => 'Frankfurt',
                'status' => 'active',
            ],
        ], null, $echo);
        break;

    case str_starts_with($path, '/v1/banks/'):
        ok(['id' => 'inst_icbc', 'slug' => 'icbc'], null, $echo);
        break;

    default:
        fail(404, 'NOT_FOUND', 'No route for ' . $path);
}

