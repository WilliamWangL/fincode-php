<?php

declare(strict_types=1);

namespace FinCode\Resources;

use FinCode\Client;
use FinCode\Response;

/**
 * Bank directory: list, search, detail and branches.
 */
final class BankResource extends Resource
{
    /**
     * List institutions, cursor paginated.
     */
    public function list(?string $country = null, ?int $limit = null, ?string $cursor = null): Response
    {
        return $this->fetch('/banks', [
            'country' => $country,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * Search by English name, local name or short name.
     */
    public function search(
        string $q,
        ?string $country = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Response {
        return $this->fetch('/banks/search', [
            'q' => $q,
            'country' => $country,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * Fetch one institution by id or by any attached identifier.
     */
    public function get(string $identifier): Response
    {
        return parent::fetch('/banks/' . Client::quote($identifier));
    }

    /**
     * Fetch one institution by its public slug.
     */
    public function bySlug(string $slug): Response
    {
        return parent::fetch('/banks/by-slug/' . Client::quote($slug));
    }

    /**
     * List the branches of one institution, cursor paginated.
     */
    public function branches(
        string $identifier,
        ?string $q = null,
        ?string $city = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Response {
        return parent::fetch('/banks/' . Client::quote($identifier) . '/branches', [
            'q' => $q,
            'city' => $city,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }
}
