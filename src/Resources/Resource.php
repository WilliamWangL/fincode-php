<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Client;
use FinCode\Response;

/**
 * Base class for the typed endpoint groups.
 */
abstract class Resource
{
    public function __construct(protected readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function fetch(string $path, array $query = []): Response
    {
        return $this->client->request('GET', $path, $query);
    }
}
