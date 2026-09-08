<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Retry-Decorator für transiente Source-Fehler (HTTP 429 / 5xx).
 *
 * Wickelt einen SourceFetcher und wiederholt GETs, die mit einer
 * SourceException mit httpStatus 429 oder >= 500 fehlschlagen — maximal
 * maxAttempts Versuche gesamt. Retry-After (aus der Exception) wird
 * respektiert und auf 1..30s geklemmt; sonst exponentielles Backoff
 * (1s, 2s). Permanente 4xx und andere Fehler werden NICHT wiederholt.
 *
 * Nach ausgeschöpften Retries wird die letzte Exception weitergeworfen —
 * die Fail-Closed-Semantik des Adapters (categoryMemberships = null)
 * bleibt unangetastet.
 */
final class RetryingSourceFetcher implements SourceFetcher
{
    /** @var callable(int): void */
    private $sleep;

    /** @param int $maxAttempts Gesamtzahl der Versuche pro Request (>= 1) */
    public function __construct(
        private SourceFetcher $inner,
        private int $maxAttempts = 3,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $s): bool => sleep($s) !== false;
    }

    public function get(string $url): array
    {
        $last = null;
        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            try {
                return $this->inner->get($url);
            } catch (SourceException $e) {
                $status = $e->httpStatus();
                $transient = $status === 429 || ($status !== null && $status >= 500);
                if (!$transient || $attempt === $this->maxAttempts) {
                    throw $e;
                }
                $last = $e;
                $wait = $e->retryAfter() ?? 2 ** ($attempt - 1); // 1s, 2s
                ($this->sleep)(max(1, min(30, $wait)));
            }
        }
        throw $last ?? SourceException::because('unreachable');
    }
}
