<?php

declare(strict_types=1);

namespace FinCode;

/**
 * Decoded response envelope: `{"data": ..., "meta": {...}}`.
 */
final class Response
{
    /**
     * @param mixed                $data Decoded `data` member.
     * @param array<string, mixed> $meta Decoded `meta` member.
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Wrap a decoded JSON body, tolerating documents without an envelope.
     */
    public static function from(mixed $body): self
    {
        if (!is_array($body)) {
            return new self($body);
        }

        $meta = $body['meta'] ?? [];

        return new self($body['data'] ?? null, is_array($meta) ? $meta : []);
    }

    /**
     * Identifier of the call on the server side.
     */
    public function requestId(): ?string
    {
        $value = $this->meta['request_id'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Cursor of the next page for paginated collections.
     */
    public function nextCursor(): ?string
    {
        $value = $this->meta['next_cursor'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
