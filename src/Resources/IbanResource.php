<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Client;
use FinCode\Response;

/**
 * IBAN validation and lookup.
 */
final class IbanResource extends Resource
{
    /**
     * Check format, country, length, MOD-97 checksum and BBAN structure.
     */
    public function validate(string $iban): Response
    {
        return $this->fetch('/iban/validate', ['iban' => $iban]);
    }

    /**
     * Validate an IBAN and resolve the bank behind it.
     */
    public function lookup(string $iban): Response
    {
        return $this->fetch('/iban/' . Client::quote($iban));
    }
}
