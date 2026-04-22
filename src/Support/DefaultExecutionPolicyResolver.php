<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Attributes\Execution as ExecutionAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\ExecutionPolicyResolver;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReflectionClass;

class DefaultExecutionPolicyResolver implements ExecutionPolicyResolver
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function resolve(Swarm $swarm): ExecutionMode
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(ExecutionAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->mode;
        }

        return ExecutionMode::from((string) $this->config->get('swarm.execution.mode', ExecutionMode::Sync->value));
    }
}
