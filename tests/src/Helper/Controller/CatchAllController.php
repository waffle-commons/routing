<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper\Controller;

use Waffle\Commons\Routing\Attribute\Route;

/**
 * Catch-all fixture: low-priority route that must be sorted to the tail of the
 * compiled table and therefore matched ONLY when no higher-priority route claims
 * the URI. Exercises the new descending-priority sort introduced in Beta-1
 * Phase 3 (Task 3.1).
 */
#[Route(path: '/', name: 'catchall', priority: -1000)]
final class CatchAllController
{
    /**
     * Matches a single dynamic segment. The Router's segment-aware matcher only
     * understands `{name}` placeholders, so the catch-all is one-segment-wide;
     * regex constraints (e.g. `{path:.*}`) are out of scope for this phase.
     */
    #[Route(path: '{anything}', name: 'fallback')]
    public function fallback(string $_anything): void {}
}
