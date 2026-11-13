<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Retry signal a store's resume() throws when its wait-generation fence changes.
 * The package lifecycle controller catches it to retry from a fresh transaction.
 */
class DurableWaitSetChangedException extends SwarmException {}
