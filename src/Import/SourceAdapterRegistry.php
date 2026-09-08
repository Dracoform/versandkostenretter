<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;

/**
 * Registry/factory mapping VSKR_shops.source_type to an adapter instance.
 * Adding a new source type = one class + one registration line.
 */
final class SourceAdapterRegistry
{
    /** @var array<string, SourceAdapter> */
    private array $adapters = [];

    public function __construct(SourceAdapter ...$adapters)
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(SourceAdapter $adapter): void
    {
        $this->adapters[$adapter->type()] = $adapter;
    }

    public function has(string $type): bool
    {
        return isset($this->adapters[$type]);
    }

    public function for(string $sourceType): SourceAdapter
    {
        if (!isset($this->adapters[$sourceType])) {
            throw new RuntimeException('No source adapter registered for type "' . $sourceType . '".');
        }
        return $this->adapters[$sourceType];
    }

    /** @return list<string> */
    public function knownTypes(): array
    {
        return array_keys($this->adapters);
    }
}
