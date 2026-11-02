<?php

declare(strict_types=1);

/**
 * Dependency free test suite. Starts PHP's built-in server on a free port and
 * drives the client over real HTTP:
 *
 *     php tests/run.php
 */

use FinCode\Client;
use FinCode\FinCodeError;

spl_autoload_register(static function (string $class): void {
    $prefix = 'FinCode\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;

    try {
        $test();
        $passed++;
        printf("  ok   %s\n", $name);
    } catch (Throwable $error) {
        $failed++;
        printf("  fail %s\n       %s\n", $name, $error->getMessage());
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message === '' ? '' : $message . ': ')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $actual, string $message): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a port: ' . $error);
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

$state = sys_get_temp_dir() . '/fincode-php-tests-' . getmypid();
if (!is_dir($state) && !mkdir($state, 0777, true) && !is_dir($state)) {
    fwrite(STDERR, "Unable to create the state directory {$state}\n");
    exit(1);
}
putenv('FINCODE_TEST_STATE=' . $state);

$port = freePort();
$log = $state . '/server.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    dirname(__DIR__),
);

if (!is_resource($server)) {
    fwrite(STDERR, "Unable to start the test server\n");
    exit(1);
}

register_shutdown_function(static function () use ($server, $state): void {
    proc_terminate($server);
    proc_close($server);

    foreach (glob($state . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($state);
});

$ready = false;
$deadline = microtime(true) + 10.0;
while (microtime(true) < $deadline) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(50_000);
}

if (!$ready) {
    fwrite(STDERR, "The test server did not start:\n" . @file_get_contents($log) . "\n");
    exit(1);
}

$baseUrl = 'http://127.0.0.1:' . $port . '/v1';
$client = new Client('sk_test', $baseUrl);

echo "fincode-php test suite\n";

check('success envelope, bearer auth and request path', static function () use ($client): void {
    $response = $client->swift->get('ICBKCNBJ');

    assertSame('ICBKCNBJ', $response->data['swift_code']);
    assertSame('Industrial and Commercial Bank of China', $response->data['bank']['name_en']);
    assertSame('req_ok', $response->requestId());
    assertSame(null, $response->nextCursor());
    assertSame('Bearer sk_test', $response->meta['echo']['auth']);
    assertSame('/v1/swift/ICBKCNBJ', $response->meta['echo']['path']);
});

check('query parameters are encoded and nulls omitted', static function () use ($client): void {
    $response = $client->banks->list('US', 5);
    assertSame(['country' => 'US', 'limit' => '5'], $response->meta['echo']['query']);
    assertSame('/v1/banks', $response->meta['echo']['path']);

    $branches = $client->banks->branches('inst_icbc', 'Main', 'Beijing', 10, 'abc');
    assertSame(
        ['q' => 'Main', 'city' => 'Beijing', 'limit' => '10', 'cursor' => 'abc'],
        $branches->meta['echo']['query'],
    );
});

check('paths are escaped segment by segment', static function () use ($client): void {
    $response = $client->banks->bySlug('a b/c');
    assertSame('/v1/banks/by-slug/a%20b%2Fc', $response->meta['echo']['path']);

    $lookup = $client->iban->lookup('DE89370400440532013000');
    assertSame('/v1/iban/DE89370400440532013000', $lookup->meta['echo']['path']);
});

check('country iban format path', static function () use ($client): void {
    $response = $client->countries->ibanFormat('US');
    assertSame('US', $response->data['country_code']);
    assertSame('/v1/countries/US/iban-format', $response->meta['echo']['path']);
});

check('api errors carry code, status and request id', static function () use ($client): void {
    try {
        $client->swift->get('NOPE');
        throw new RuntimeException('expected a FinCodeError');
    } catch (FinCodeError $error) {
        assertSame('INVALID_SWIFT', $error->errorCode);
        assertSame(422, $error->status);
        assertSame('req_err', $error->requestId);
        assertSame('Not a valid BIC', $error->getMessage());
        assertTrue(!$error->isRetryable(), 'validation errors must not be retryable');
    }
});

check('retry-after is exposed on rate limit errors', static function () use ($baseUrl): void {
    $client = new Client('sk_test', $baseUrl, 5.0, 0);

    try {
        $client->swift->get('LIMITED');
        throw new RuntimeException('expected a FinCodeError');
    } catch (FinCodeError $error) {
        assertSame(429, $error->status);
        assertSame(7.0, $error->retryAfter);
        assertTrue($error->isRetryable(), 'rate limits are retryable');
    }
});

check('429 is retried and then succeeds', static function () use ($client): void {
    $response = $client->swift->get('FLAKY');

    assertSame('ICBKCNBJ', $response->data['swift_code']);
    $hits = $client->request('GET', '/__hits', ['name' => 'flaky']);
    assertSame(2, $hits->data['count']);
});

check('retries are exhausted after max_retries', static function () use ($baseUrl): void {
    $client = new Client('sk_test', $baseUrl, 5.0, 2);

    try {
        $client->swift->get('BROKEN');
        throw new RuntimeException('expected a FinCodeError');
    } catch (FinCodeError $error) {
        assertSame(503, $error->status);
        assertSame('UNAVAILABLE', $error->errorCode);
    }

    $hits = $client->request('GET', '/__hits', ['name' => 'broken']);
    assertSame(3, $hits->data['count'], 'one attempt plus two retries');
});

check('timeouts surface as TIMEOUT', static function () use ($baseUrl): void {
    $client = new Client('sk_test', $baseUrl, 0.05, 0);

    try {
        $client->swift->get('SLOW');
        throw new RuntimeException('expected a FinCodeError');
    } catch (FinCodeError $error) {
        assertSame('TIMEOUT', $error->errorCode);
        assertSame(0, $error->status);
        assertTrue($error->isRetryable(), 'timeouts are retryable');
    }
});

check('pagination follows the cursor', static function () use ($client): void {
    $ids = [];
    $queries = [];

    foreach ($client->iterPages('/banks', ['country' => 'US'], 1) as $page) {
        foreach ($page->data as $bank) {
            $ids[] = $bank['id'];
        }
        $queries[] = $page->meta['echo']['query'];
    }

    assertSame(['inst_1', 'inst_2'], $ids);
    assertSame(['country' => 'US', 'limit' => '1'], $queries[0]);
    assertTrue(!array_key_exists('cursor', $queries[0]), 'a null cursor must be omitted');
    assertSame(['country' => 'US', 'limit' => '1', 'cursor' => 'next'], $queries[1]);
});

check('escape hatch sends a json body', static function () use ($client): void {
    $response = $client->request('POST', '/echo', [], ['name' => 'Test']);

    assertSame('POST', $response->data['echo']['method']);
    assertSame(['name' => 'Test'], $response->data['echo']['body']);
    assertSame('req_echo', $response->requestId());
});

check('lookup resource builds the query', static function () use ($client): void {
    $response = $client->lookup->get('ICBKCNBJ');

    assertSame('ICBKCNBJ', $response->data['query']);
    assertSame('swift', $response->data['detected_type']);
    assertSame('ICBKCNBJ', $response->meta['echo']['query']['q']);
});

check('an api key is required', static function (): void {
    try {
        new Client('');
        throw new RuntimeException('expected an InvalidArgumentException');
    } catch (InvalidArgumentException $error) {
        assertTrue(str_contains($error->getMessage(), 'api_key'), 'message should explain the problem');
    }
});

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
