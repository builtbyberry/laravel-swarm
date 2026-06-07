<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

/**
 * @internal
 *
 * Distinct type for DI wiring only — no behaviour overrides needed.
 * StaticHierarchicalRunner::runDurableStep() is the overriding entry point;
 * PHP polymorphism routes all coordinator calls there automatically.
 */
class DurableStaticHierarchicalCoordinator extends DurableHierarchicalCoordinator {}
