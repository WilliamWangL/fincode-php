<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Response;

/**
 * Identifier detection across every supported type.
 */
final class LookupResource extends Resource
{
    /**
     * Detect the identifier type from the input and resolve it.
     */
    public function get(string $q): Response
    {
        return parent::fetch('/lookup', ['q' => $q]);
    }
}
