<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use Closure;
use Illuminate\Concurrency\Console\InvokeSerializedClosureCommand;
use Illuminate\Concurrency\ForkDriver;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Container\Container;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use ReflectionClass;
use Throwable;

/** @internal */
class ConcurrentAgentResult
{
    private ?string $transportedResult = null;

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
                if ($class === UnsupportedNativeApprovalException::class) {
                    throw new UnsupportedNativeApprovalException;
                }
                if ($class === ApprovalNotResumableException::class) {
                    throw ApprovalNotResumableException::make();
                }
                throw new SwarmException('Concurrent agent failure could not be transported ['.$class.'].');
            }
            $parameters = $this->transportedFailure['parameters'];

            throw new $class(...(! empty(array_filter($parameters, fn ($value) => $value !== null))
                ? $parameters
                : [$this->transportedFailure['message']]));
        }

        return $this->transportedResult === null ? $this->result : unserialize(base64_decode($this->transportedResult));
    }

    /**
     * Convert a worker failure to JSON-safe constructor data.
     *
     * @return array{class: class-string<Throwable>, message: string, parameters: array<string, mixed>, transport_failed: bool}
     */
    public static function failureDescriptor(Throwable $failure): array
    {
        try {
            $parameters = [];
            $reflection = new ReflectionClass($failure);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
                foreach ($constructor->getParameters() as $parameter) {
                    if ($parameter->name === 'message') {
                        $parameters[$parameter->name] = $failure->getMessage();

                        continue;
                    }
                    if ($parameter->name === 'code') {
                        $parameters[$parameter->name] = $failure->getCode();

                        continue;
                    }
                    if ($parameter->name === 'previous') {
                        $parameters[$parameter->name] = null;

                        continue;
                    }

                    $property = $reflection->hasProperty($parameter->name)
                        ? $reflection->getProperty($parameter->name)
                        : null;
                    $parameters[$parameter->name] = $property !== null
                        && $property->isPublic()
                        && $property->isInitialized($failure)
                            ? $property->getValue($failure)
                            : null;
                }
            }

            /** @var array{class: class-string<Throwable>, message: string, parameters: array<string, mixed>, transport_failed: bool} $descriptor */
            $descriptor = json_decode(json_encode([
                'class' => $failure::class,
                'message' => $failure->getMessage(),
                'parameters' => $parameters,
                'transport_failed' => false,
            ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

            return $descriptor;
        } catch (Throwable) {
            return [
                'class' => json_decode(json_encode(
                    $failure::class,
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                ), true, flags: JSON_THROW_ON_ERROR),
                'message' => 'Concurrent agent failure could not be transported.',
                'parameters' => [],
                'transport_failed' => true,
            ];
        }
    }

    /** @param array<string, mixed> $descriptor */
    public static function throwFailureDescriptor(array $descriptor): never
    {
        $class = is_string($descriptor['class'] ?? null) && is_a($descriptor['class'], Throwable::class, true)
            ? $descriptor['class']
            : SwarmException::class;

        if ($descriptor['transport_failed'] ?? false) {
            if ($class === UnsupportedNativeApprovalException::class) {
                throw new UnsupportedNativeApprovalException;
            }
            if ($class === ApprovalNotResumableException::class) {
                throw ApprovalNotResumableException::make();
            }
            throw new SwarmException('Concurrent agent failure could not be transported ['.$class.'].');
        }

        $parameters = is_array($descriptor['parameters'] ?? null) ? $descriptor['parameters'] : [];
        $message = is_string($descriptor['message'] ?? null) ? $descriptor['message'] : 'Concurrent agent failed.';

        throw new $class(...(! empty(array_filter($parameters, fn ($value) => $value !== null)) ? $parameters : [$message]));
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
            try {
                // Laravel's outer process envelope is JSON. Encode the original PHP
                // value so binary strings cannot corrupt that envelope.
                return ['result_wire' => $this->transportedResult ?? base64_encode(serialize($this->result)), 'failure' => $this->transportedFailure];
            } catch (Throwable $exception) {
                return self::capture(static fn () => throw $exception)->__serialize();
            }
        }

        $failure = self::failureDescriptor($this->failure);

        return ['result_wire' => base64_encode(serialize([])), 'failure' => $failure];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // Decode successful values only after the parent has inspected every
        // failure descriptor; object wakeup hooks must not hide a native rejection.
        $this->transportedResult = $data['result_wire'];
        $this->result = [];
        $this->transportedFailure = $data['failure'];
        $this->failure = null;
    }
}
