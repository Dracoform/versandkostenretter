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
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
