<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Thrown when the configured history table is missing columns required for queued execution leases.
 *
 * This is distinct from {@see LostSwarmLeaseException}: runtime lease loss returns null from some paths,
 * while a missing schema must surface to operators as a hard configuration error.
 */
final class MissingQueueLeaseSchemaException extends SwarmException {}
