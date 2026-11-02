<?php

declare(strict_types=1);

namespace FinCode;

use Generator;
use InvalidArgumentException;

/**
 * Client for the FinCode V1 API.
 *
 * ```php
 * $fincode = new FinCode\Client('sk_xxx');
 * $response = $fincode->swift->get('ICBKCNBJ');
 * echo $response->data['bank']['name_en'];
 * ```
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.ecommica.com/v1';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_MAX_RETRIES = 2;

    /** Statuses the API may return transiently; retried with exponential backoff. */
    public const RETRYABLE_STATUSES = [429, 502, 503, 504];

    private const USER_AGENT = 'fincode-php/1.0.0';
    private const BASE_BACKOFF_SECONDS = 0.25;

    public readonly Resources\IbanResource $iban;
    public readonly Resources\IdentifierResource $swift;
    public readonly Resources\IdentifierResource $routing;
    public readonly Resources\IdentifierResource $sortCode;
    public readonly Resources\IdentifierResource $bsb;
    public readonly Resources\IdentifierResource $ifsc;
    public readonly Resources\IdentifierResource $cnaps;
    public readonly Resources\IdentifierResource $bin;
    public readonly Resources\BankResource $banks;
    public readonly Resources\CountryResource $countries;
    public readonly Resources\LookupResource $lookup;

    private readonly string $baseUrl;

    /**
     * @param string $apiKey     Key with the `sk_` prefix.
     * @param string $baseUrl    Base URL including the version segment.
     * @param float  $timeout    Per-attempt timeout in seconds.
     * @param int    $maxRetries Retries after the first attempt for 429/502/503/504
     *                           and network errors.
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ) {
        if ($apiKey === '') {
            throw new InvalidArgumentException('FinCode: an api_key is required');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('FinCode: the timeout must be positive');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('FinCode: max_retries must not be negative');
        }

        $this->baseUrl = rtrim($baseUrl, '/');

        $this->iban = new Resources\IbanResource($this);
        $this->swift = new Resources\IdentifierResource($this, 'swift');
        $this->routing = new Resources\IdentifierResource($this, 'routing');
        $this->sortCode = new Resources\IdentifierResource($this, 'sort-code');
        $this->bsb = new Resources\IdentifierResource($this, 'bsb');
        $this->ifsc = new Resources\IdentifierResource($this, 'ifsc');
        $this->cnaps = new Resources\IdentifierResource($this, 'cnaps');
        $this->bin = new Resources\IdentifierResource($this, 'bin');
        $this->banks = new Resources\BankResource($this);
        $this->countries = new Resources\CountryResource($this);
        $this->lookup = new Resources\LookupResource($this);
    }

    /**
     * Low-level escape hatch for endpoints without a typed helper.
     *
     * @param array<string, mixed> $query Values equal to null are omitted.
     * @param mixed                $body  Encoded as JSON when not null.
     */
    public function request(string $method, string $path, array $query = [], mixed $body = null): Response
    {
        $url = $this->buildUrl($path, $query);
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $attempts = $this->maxRetries + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->send($method, $url, $payload);
            } catch (FinCodeError $error) {
                if ($attempt >= $attempts || !$error->isRetryable()) {
                    throw $error;
                }
                usleep((int) round($this->backoffDelay($attempt, $error->retryAfter) * 1_000_000));
            }
        }

        throw new \LogicException('FinCode: unreachable retry loop');
    }

    /**
     * Convenience wrapper for GET requests.
     *
     * @param array<string, mixed> $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * Yield successive pages of a cursor paginated collection.
     *
     * @param array<string, mixed> $query
     *
     * @return Generator<int, Response>
     */
    public function iterPages(string $path, array $query = [], int $limit = 100): Generator
    {
        $cursor = null;

        while (true) {
            $page = $this->request('GET', $path, $query + ['limit' => $limit, 'cursor' => $cursor]);
            yield $page;

            $cursor = $page->nextCursor();
            if ($cursor === null || $cursor === '') {
                return;
            }
        }
    }

    /**
     * Percent-encode one path segment.
     */
    public static function quote(string $value): string
    {
        return rawurlencode($value);
    }

    private function send(string $method, string $url, ?string $payload): Response
    {
        $status = 0;
        $retryAfter = null;

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMilliseconds(),
            CURLOPT_TIMEOUT_MS => $this->timeoutMilliseconds(),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: ' . self::USER_AGENT,
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$retryAfter): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                } elseif (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = self::parseRetryAfter(trim(substr($line, 12)));
                }

                return strlen($line);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $curlError = curl_error($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $errno !== 0) {
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;

            throw new FinCodeError(
                $timedOut
                    ? sprintf('Request timed out after %s s', $this->timeout)
                    : ($curlError !== '' ? $curlError : 'Network error'),
                $timedOut ? 'TIMEOUT' : 'NETWORK_ERROR',
            );
        }

        $decoded = self::decode($body);
        $status = $status !== 0 ? $status : $code;

        if ($status >= 400) {
            $envelope = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
                ? $decoded['error']
                : [];

            throw new FinCodeError(
                (string) ($envelope['message'] ?? 'Request failed with status ' . $status),
                (string) ($envelope['code'] ?? 'HTTP_' . $status),
                $status,
                isset($envelope['request_id']) ? (string) $envelope['request_id'] : null,
                $retryAfter,
            );
        }

        return Response::from($decoded);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $pairs === [] ? $url : $url . '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function timeoutMilliseconds(): int
    {
        return max(1, (int) round($this->timeout * 1000));
    }

    private function backoffDelay(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $jitter = mt_rand() / mt_getrandmax();

        return self::BASE_BACKOFF_SECONDS * (2 ** ($attempt - 1)) + $jitter * self::BASE_BACKOFF_SECONDS;
    }

    private static function parseRetryAfter(string $value): ?float
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;

        return $seconds > 0 ? $seconds : null;
    }

    private static function decode(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
