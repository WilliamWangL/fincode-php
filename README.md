# fincode-php

PHP client for the [FinCode](https://fincode.ecommica.com) global financial data API —
IBAN validation and bank lookup, SWIFT/BIC, US routing numbers, UK sort codes, Australian BSB,
Indian IFSC, China CNAPS, card BIN/IIN, bank and country directories.

- PHP 8.1+, only `ext-curl` and `ext-json`
- Bearer authentication, per-attempt timeouts, retries with exponential backoff
- Envelope aware responses plus a raw escape hatch for new endpoints
- Errors carry the API code, HTTP status, request id and `Retry-After`

## Install

The package is distributed from GitHub. Add the repository and require it:

```bash
composer config repositories.fincode vcs https://github.com/WilliamWangL/fincode-php
composer require fincode/fincode-php:^1.0
```

Without Composer, clone the repository and map the namespace yourself:

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'FinCode\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/fincode-php/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
```

## Quickstart

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use FinCode\Client;

$fincode = new Client(getenv('FINCODE_API_KEY'));

$response = $fincode->swift->get('ICBKCNBJ');

echo $response->data['bank']['name_en']; // Industrial and Commercial Bank of China
echo $response->requestId();              // req_1a2b3c4d
```

## Configuration

```php
$fincode = new Client(
    apiKey: getenv('FINCODE_API_KEY'),
    baseUrl: 'https://api.ecommica.com/v1',
    timeout: 10.0,
    maxRetries: 2,
);
```

| Argument     | Default                       | Description                                                             |
| ------------ | ----------------------------- | ----------------------------------------------------------------------- |
| `apiKey`     | –                             | Key with the `sk_` prefix.                                              |
| `baseUrl`    | `https://api.ecommica.com/v1` | Base URL including the version segment.                                 |
| `timeout`    | `10.0`                        | Per-attempt timeout in seconds.                                         |
| `maxRetries` | `2`                           | Retries after the first attempt for 429/502/503/504 and network errors. |

## Methods

Every method returns a `FinCode\Response` with `data`, `meta`, `requestId()` and `nextCursor()`.

| Method                                   | Endpoint                              |
| ---------------------------------------- | ------------------------------------- |
| `$fincode->iban->validate($iban)`        | `GET /v1/iban/validate?iban=`         |
| `$fincode->iban->lookup($iban)`          | `GET /v1/iban/{iban}`                 |
| `$fincode->swift->get($code)`            | `GET /v1/swift/{code}`                |
| `$fincode->routing->get($number)`        | `GET /v1/routing/{number}`            |
| `$fincode->sortCode->get($code)`         | `GET /v1/sort-code/{code}`            |
| `$fincode->bsb->get($code)`              | `GET /v1/bsb/{code}`                  |
| `$fincode->ifsc->get($code)`             | `GET /v1/ifsc/{code}`                 |
| `$fincode->cnaps->get($code)`            | `GET /v1/cnaps/{code}`                |
| `$fincode->bin->get($bin)`               | `GET /v1/bin/{bin}`                   |
| `$fincode->banks->list(...)`             | `GET /v1/banks`                       |
| `$fincode->banks->search($q, ...)`       | `GET /v1/banks/search?q=`             |
| `$fincode->banks->get($identifier)`      | `GET /v1/banks/{id}`                  |
| `$fincode->banks->bySlug($slug)`         | `GET /v1/banks/by-slug/{slug}`        |
| `$fincode->banks->branches($id, ...)`    | `GET /v1/banks/{id}/branches`         |
| `$fincode->countries->list()`            | `GET /v1/countries`                   |
| `$fincode->countries->get($code)`        | `GET /v1/countries/{code}`            |
| `$fincode->countries->ibanFormat($code)` | `GET /v1/countries/{code}/iban-format` |
| `$fincode->lookup->get($q)`              | `GET /v1/lookup?q=`                   |

Anything else is one call away:

```php
$response = $fincode->request('GET', '/banks/by-slug/chase');
$bank = $response->data;
```

## Pagination

```php
foreach ($fincode->iterPages('/banks', ['country' => 'US'], 100) as $page) {
    foreach ($page->data as $bank) {
        echo $bank['name_en'], PHP_EOL;
    }
}
```

## Errors and retries

```php
use FinCode\FinCodeError;

try {
    $fincode->swift->get('NOPE');
} catch (FinCodeError $error) {
    echo $error->errorCode;   // INVALID_SWIFT
    echo $error->status;      // 422
    echo $error->requestId;   // req_5e6f7a8b
    echo $error->retryAfter;  // seconds, when the API sent Retry-After
}
```

`429`, `502`, `503` and `504` responses — plus network failures and timeouts — are retried with
exponential backoff, honouring `Retry-After`. Other `4xx` validation errors are never retried.

## Development

```bash
composer install   # optional, the suite has no dependencies
php tests/run.php
```

## Links

- API documentation: https://fincode.ecommica.com/docs
- SDK guide: https://fincode.ecommica.com/docs/sdks
- Issues: https://github.com/WilliamWangL/fincode-php/issues

## License

MIT
