<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

class SwarmCapture
{
    public const REDACTED = '[redacted]';

    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function input(string $input): string
    {
        return $this->capturesInputs() ? $input : self::REDACTED;
    }

    public function output(string $output): string
    {
        return $this->capturesOutputs() ? $output : self::REDACTED;
    }

    public function failureMessage(Throwable $exception): string
    {
        return $this->capturesFailures() ? $exception->getMessage() : self::REDACTED;
    }

    public function failureException(Throwable $exception): Throwable
    {
        return $this->capturesFailures() ? $exception : new SwarmException(self::REDACTED);
    }

    /**
     * @param  array<int, SwarmArtifact>  $artifacts
     * @return array<int, SwarmArtifact>
     */
    public function artifacts(array $artifacts): array
    {
        return $this->capturesArtifacts() ? $artifacts : [];
    }

    public function context(RunContext $context): RunContext
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $context;
        }

        $data = $context->data;

        if (! $this->capturesInputs()) {
            $data = ['input' => self::REDACTED];
        }

        if (! $this->capturesOutputs()) {
            foreach (['last_output', 'hierarchical_node_outputs', 'durable_hierarchical_cursor'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = self::REDACTED;
                }
            }
        }

        return new RunContext(
            runId: $context->runId,
            input: $this->input($context->input),
            data: $data,
            metadata: $context->metadata,
            artifacts: $this->artifacts($context->artifacts),
        );
    }

    public function activeContext(RunContext $context): RunContext
    {
        if ($this->capturesActiveContext()) {
            return $context;
        }

        return new RunContext(
            runId: $context->runId,
            input: self::REDACTED,
            data: ['input' => self::REDACTED],
            metadata: $context->metadata,
            artifacts: [],
        );
    }

    public function terminalContext(RunContext $context): RunContext
    {
        if (! $this->capturesActiveContext()) {
            return $this->activeContext($context);
        }

        return $this->context($context);
    }

    public function response(SwarmResponse $response): SwarmResponse
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $response;
        }

        return new SwarmResponse(
            output: $this->output($response->output),
            steps: array_map(fn (SwarmStep $step): SwarmStep => $this->step($step), $response->steps),
            usage: $response->usage,
            context: $response->context !== null ? $this->context($response->context) : null,
            artifacts: $this->artifacts($response->artifacts),
            metadata: $response->metadata,
        );
    }

    public function step(SwarmStep $step): SwarmStep
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $step;
        }

        return new SwarmStep(
            agentClass: $step->agentClass,
            input: $this->input($step->input),
            output: $this->output($step->output),
            artifacts: $this->artifacts($step->artifacts),
            metadata: $step->metadata,
        );
    }

    public function capturesInputs(): bool
    {
        return (bool) $this->config->get('swarm.capture.inputs', true);
    }

    public function capturesOutputs(): bool
    {
        return (bool) $this->config->get('swarm.capture.outputs', true);
    }

    public function capturesArtifacts(): bool
    {
        return $this->capturesOutputs() && (bool) $this->config->get('swarm.capture.artifacts', true);
    }

    public function capturesActiveContext(): bool
    {
        return (bool) $this->config->get('swarm.capture.active_context', true);
    }

    public function capturesFailures(): bool
    {
        return $this->capturesInputs() && $this->capturesOutputs();
    }
}
