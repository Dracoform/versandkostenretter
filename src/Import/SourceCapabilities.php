<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Marker interface for OPTIONAL source capabilities.
 *
 * An adapter may implement any of these; the orchestrator/registry detects
 * capabilities via instanceof without invoking them. This lets the
 * architecture gain narrow future features (e.g. live availability checks)
 * without redesigning the importer — and without imposing them on sources
 * that don't need them (Shopify's catalogue import already carries
 * availability; it does not implement live checking).
 */
interface SourceCapabilities
{
    // Marker only. Concrete capabilities are separate interfaces:
    //   AvailabilityChecker — fresh availability check for a small batch of
    //                         display candidates (deliberately NOT invoked
    //                         during searches or catalogue imports).
}
