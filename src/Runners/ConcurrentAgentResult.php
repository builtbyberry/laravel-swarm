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

        $parameters = [];
        try {
            $reflection = new ReflectionClass($this->failure);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
                foreach ($constructor->getParameters() as $parameter) {
                    $parameters[$parameter->name] = $this->failure->{$parameter->name} ?? null;
                }
            }
            // Reduce the entire descriptor to JSON-safe data before the outer
            // PHP transport; neither captured objects nor malformed text escape.
            $failure = json_decode(json_encode([
                'class' => $this->failure::class,
                'message' => $this->failure->getMessage(),
                'parameters' => $parameters,
                'transport_failed' => false,
            ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $failure = [
                'class' => json_decode(json_encode($this->failure::class, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true),
                'message' => 'Concurrent agent failure could not be transported.',
                'parameters' => [],
                'transport_failed' => true,
            ];
        }

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
