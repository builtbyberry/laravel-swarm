<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmInputGuardrail;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmOutputGuardrail;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Enums\GuardrailChildInheritance;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Resolves and invokes configured and swarm-declared guardrails.
 *
 * Merge order: global config entries for the phase, then swarm {@see DefinesGuardrails::guardrails()},
 * then (when {@see GuardrailChildInheritance::OwnGlobalAndParent} and parent run resolves) parent swarm guardrails.
 */
class SwarmGuardrailRunner
{
    public function __construct(
        protected Container $container,
        protected ConfigRepository $config,
        protected RunHistoryStore $historyStore,
        protected LoggerInterface $logger,
    ) {}

    public function validateInput(Swarm $swarm, RunContext $context): void
    {
        foreach ($this->resolvePhase(SwarmInputGuardrail::class, $swarm, $context) as $guardrail) {
            $guardrail->validate($context);
        }
    }

    public function validateStep(Swarm $swarm, GuardrailStepContext $step, RunContext $executionContext): void
    {
        foreach ($this->resolvePhase(SwarmStepGuardrail::class, $swarm, $executionContext) as $guardrail) {
            $guardrail->validate($step);
        }
    }

    public function validateOutput(Swarm $swarm, RunContext $context, string $output): void
    {
        foreach ($this->resolvePhase(SwarmOutputGuardrail::class, $swarm, $context) as $guardrail) {
            $guardrail->validate($context, $output);
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return iterable<int, T>
     */
    protected function resolvePhase(string $contract, Swarm $swarm, RunContext $inheritContext): iterable
    {
        $merged = [];

        foreach ($this->configuredRefsForContract($contract) as $ref) {
            $merged[] = $ref;
        }

        foreach ($this->refsFromDefinesGuardrails($swarm) as $ref) {
            $merged[] = $ref;
        }

        if ($this->childInheritanceMode() === GuardrailChildInheritance::OwnGlobalAndParent) {
            foreach ($this->refsFromParentSwarm($inheritContext) as $ref) {
                $merged[] = $ref;
            }
        }

        foreach ($merged as $ref) {
            $instance = $this->resolveRef($ref);

            if ($instance instanceof $contract) {
                yield $instance;
            }
        }
    }

    /**
     * @param  class-string  $contract
     * @return list<mixed>
     */
    protected function configuredRefsForContract(string $contract): array
    {
        $key = match ($contract) {
            SwarmInputGuardrail::class => 'input',
            SwarmStepGuardrail::class => 'step',
            SwarmOutputGuardrail::class => 'output',
            default => null,
        };

        if ($key === null) {
            return [];
        }

        /** @var mixed $refs */
        $refs = $this->config->get("swarm.guardrails.{$key}", []);

        return is_array($refs) ? array_values($refs) : [];
    }

    /**
     * @return list<mixed>
     */
    protected function refsFromDefinesGuardrails(Swarm $swarm): array
    {
        if (! $swarm instanceof DefinesGuardrails) {
            return [];
        }

        return array_values($swarm->guardrails());
    }

    /**
     * @return list<mixed>
     */
    protected function refsFromParentSwarm(RunContext $context): array
    {
        $parentRunId = $context->metadata['parent_run_id'] ?? null;

        if (! is_string($parentRunId) || $parentRunId === '') {
            return [];
        }

        $parentRecord = $this->historyStore->find($parentRunId);

        if (! is_array($parentRecord)) {
            return [];
        }

        $parentSwarmClass = $parentRecord['swarm_class'] ?? null;

        if (! is_string($parentSwarmClass)) {
            return [];
        }

        if (! class_exists($parentSwarmClass)) {
            $this->logger->warning('Laravel Swarm: parent swarm class [{class}] stored in run [{parent_run_id}] does not exist; parent guardrails will not be inherited. This may indicate a renamed or removed class.', [
                'class' => $parentSwarmClass,
                'parent_run_id' => $parentRunId,
            ]);

            return [];
        }

        try {
            $parentSwarm = $this->container->make($parentSwarmClass);
        } catch (\Throwable $e) {
            $this->logger->warning('Laravel Swarm: parent swarm [{class}] could not be resolved from the container; parent guardrails will not be inherited. Check your container bindings.', [
                'class' => $parentSwarmClass,
                'parent_run_id' => $parentRunId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $parentSwarm instanceof DefinesGuardrails || ! $parentSwarm instanceof Swarm) {
            return [];
        }

        return array_values($parentSwarm->guardrails());
    }

    protected function childInheritanceMode(): GuardrailChildInheritance
    {
        $raw = (string) $this->config->get('swarm.guardrails.child_inheritance', GuardrailChildInheritance::OwnAndGlobal->value);

        return GuardrailChildInheritance::tryFrom($raw) ?? GuardrailChildInheritance::OwnAndGlobal;
    }

    protected function resolveRef(mixed $ref): object
    {
        if (is_object($ref)) {
            return $ref;
        }

        if (is_string($ref) && class_exists($ref)) {
            return $this->container->make($ref);
        }

        throw new SwarmException('Guardrail reference must be a class name resolvable from the container or an object instance.');
    }
}
