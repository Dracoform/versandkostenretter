<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Anything that can fetch a source feed. Lets tests substitute fetchers
 * without network access.
 */
interface SourceFetcher
{
    /** @return array{code:int, body:string, content_type:string, url:string} */
    public function get(string $url): array;
}
