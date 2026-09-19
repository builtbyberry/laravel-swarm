<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Closure;
use Illuminate\Concurrency\Console\InvokeSerializedClosureCommand;
use Illuminate\Concurrency\ForkDriver;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Container\Container;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionClass;
use Throwable;

/** @internal */
class ConcurrentAgentResult
{
    /** @var array<string, mixed>|null */
    private ?array $transportedFailure = null;

    /** @param array<mixed> $result */
    private function __construct(private array $result, private ?Throwable $failure = null) {}

    /**
     * Wrap only built-in concurrent callbacks. Serial and custom driver semantics stay intact.
     *
     * @param  array<Closure>  $callbacks
     * @return array<Closure>
     */
    public static function wrapCallbacks(Driver $driver, array $callbacks): array
    {
        if (! $driver instanceof ProcessDriver && ! $driver instanceof ForkDriver) {
            return $callbacks;
        }

        $wrapped = [];
        foreach ($callbacks as $key => $callback) {
            $wrapped[$key] = static function () use ($callback): ConcurrentAgentResult {
                return ConcurrentAgentResult::capture($callback);
            };
        }

        return $wrapped;
    }

    public static function capture(Closure $callback): self
    {
        try {
            return new self($callback());
        } catch (Throwable $exception) {
            // Keep worker-side reporting, without letting a reporting failure hide
            // the outcome that the parent must inspect before allowing retries.
            try {
                Container::getInstance()->make(ExceptionHandler::class)->report($exception);
            } catch (Throwable) {
                // The original failure remains the batch's decision input.
            }

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
            if ($this->transportedFailure['transport_failed'] ?? false) {
                throw new SwarmException('Concurrent agent failure could not be transported ['.$class.'].');
            }
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

        $parameters = [];
        $transportFailed = false;
        try {
            $reflection = new ReflectionClass($this->failure);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
                foreach ($constructor->getParameters() as $parameter) {
                    $parameters[$parameter->name] = $this->failure->{$parameter->name} ?? null;
                }
            }
            // Reduce properties to the JSON wire shape before PHP serialization:
            // never transport a live object, closure, resource, or exception trace.
            $parameters = json_decode(json_encode($parameters, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $parameters = [];
            $transportFailed = true;
        }

        return ['result' => [], 'failure' => [
            'class' => $this->failure::class,
            'message' => $this->failure->getMessage(),
            'parameters' => $parameters,
            'transport_failed' => $transportFailed,
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
