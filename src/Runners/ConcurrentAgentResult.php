<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use Closure;
use Illuminate\Concurrency\Console\InvokeSerializedClosureCommand;
use Illuminate\Concurrency\ProcessDriver;
use ReflectionClass;
use Throwable;

/** @internal */
class ConcurrentAgentResult
{
    /** @var array<string, mixed>|null */
    private ?array $transportedFailure = null;

    /** @param array<mixed> $result */
    private function __construct(private array $result, private ?Throwable $failure = null) {}

    public static function capture(Closure $callback): self
    {
        try {
            return new self($callback());
        } catch (Throwable $exception) {
            return new self([], $exception);
        }
    }

    public function failureClass(): ?string
    {
        return $this->failure !== null ? $this->failure::class : ($this->transportedFailure['class'] ?? null);
    }

    /** @return array<mixed> */
    public function value(): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->transportedFailure !== null) {
            /** @var class-string<Throwable> $class */
            $class = $this->transportedFailure['class'];
            $parameters = $this->transportedFailure['parameters'];

            throw new $class(...(! empty(array_filter($parameters, fn ($value) => $value !== null))
                ? $parameters
                : [$this->transportedFailure['message']]));
        }

        return $this->result;
    }

    /**
     * Transport constructor data without serializing exception traces or captured objects.
     *
     * @see InvokeSerializedClosureCommand
     * @see ProcessDriver
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        if ($this->failure === null) {
            return ['result' => $this->result, 'failure' => $this->transportedFailure];
        }

        $reflection = new ReflectionClass($this->failure);
        $constructor = $reflection->getConstructor();
        $parameters = [];
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
            foreach ($constructor->getParameters() as $parameter) {
                $parameters[$parameter->name] = $this->failure->{$parameter->name} ?? null;
            }
        }

        return ['result' => [], 'failure' => [
            'class' => $this->failure::class,
            'message' => $this->failure->getMessage(),
            'parameters' => $parameters,
        ]];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->result = $data['result'];
        $this->transportedFailure = $data['failure'];
        $this->failure = null;
    }
}
