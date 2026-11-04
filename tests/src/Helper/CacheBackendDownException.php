<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Routing\Helper;

use RuntimeException;
use Waffle\Commons\Contracts\Cache\Exception\CacheBackendUnavailableExceptionInterface;

/**
 * What a cache adapter throws when its backend cannot be reached (Redis refusing
 * connections, a DNS failure): the signal the router degrades on (RFC-013 §4).
 */
final class CacheBackendDownException extends RuntimeException implements CacheBackendUnavailableExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Redis backend unavailable: connection refused');
    }

    #[\Override]
    public function getBackend(): string
    {
        return 'redis';
    }
}
