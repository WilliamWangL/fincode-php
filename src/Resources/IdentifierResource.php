<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Client;
use FinCode\Response;

/**
 * Typed lookup for one national identifier, e.g. SWIFT or IFSC.
 */
final class IdentifierResource extends Resource
{
    public function __construct(Client $client, private readonly string $segment)
    {
        parent::__construct($client);
    }

    /**
     * Resolve one identifier value, e.g. `ICBKCNBJ` or `HDFC0000001`.
     */
    public function get(string $value): Response
    {
        return parent::fetch('/' . $this->segment . '/' . Client::quote($value));
    }
}
