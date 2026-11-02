<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Client;
use FinCode\Response;

/**
 * Country reference data and national IBAN formats.
 */
final class CountryResource extends Resource
{
    /**
     * List every supported country.
     */
    public function list(): Response
    {
        return $this->fetch('/countries');
    }

    /**
     * Fetch one country by ISO 3166-1 alpha-2 code.
     */
    public function get(string $code): Response
    {
        return parent::fetch('/countries/' . Client::quote($code));
    }

    /**
     * Fetch the IBAN structure of one country.
     */
    public function ibanFormat(string $code): Response
    {
        return parent::fetch('/countries/' . Client::quote($code) . '/iban-format');
    }
}
