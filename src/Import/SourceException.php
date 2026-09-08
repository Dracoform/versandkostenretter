<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;

/**
 * Thrown when the SOURCE (fetch/HTTP/structure/JSON/mapping) fails.
 * Distinct from database errors so the CLI can fail closed without
 * touching availability data.
 */
final class SourceException extends RuntimeException
{
    /** HTTP status the source returned, when the failure was an HTTP status. */
    private ?int $httpStatus = null;

    /** Retry-After header value (seconds) when the source provided one. */
    private ?int $retryAfter = null;

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function http(int $status, ?int $retryAfter = null): self
    {
        $e = new self(
            "Server error HTTP {$status} from source"
            . ($retryAfter !== null ? " (Retry-After: {$retryAfter}s)" : '')
            . '.'
        );
        $e->httpStatus = $status;
        $e->retryAfter = $retryAfter;
        return $e;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
