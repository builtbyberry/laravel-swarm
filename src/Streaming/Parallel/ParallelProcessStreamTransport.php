<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\ParallelAgentResolver;
use BuiltByBerry\LaravelSwarm\Runners\ParallelStreamBranchWorker;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;

use function Illuminate\Support\artisan_binary;
use function Illuminate\Support\php_binary;

/** @internal */
final class ParallelProcessStreamTransport
{
    public function __construct(private ProcessFactory $processes) {}

    /**
     * Exercise the provider-free child bootstrap and authenticated loopback
     * handshake used by live parallel streams.
     */
    public function assertReady(int $maxFrameBytes, int $cancelGraceMilliseconds, int $timeoutSeconds = 5): void
    {
        $branchId = 'health:0';
        $deadline = (float) hrtime(true) + ($timeoutSeconds * 1_000_000_000);
        $session = $this->start([
            $branchId => static fn (string $endpoint, string $token, float $workerDeadline, int $maxFrameBytes): array => self::runReadinessWorker(
                $endpoint,
                $token,
                $workerDeadline,
                $maxFrameBytes,
                $branchId,
            ),
        ], $deadline, $maxFrameBytes, $cancelGraceMilliseconds);

        $events = $session->events();
        foreach ($events as $envelope) {
            if (($envelope['terminal'] ?? false) !== true) {
                throw new SwarmException('Parallel stream readiness worker emitted an unexpected event.');
            }
        }
        $outcomes = $events->getReturn();
        if (($outcomes[$branchId]['ok'] ?? null) !== true) {
            throw new SwarmException('Parallel stream readiness worker did not complete its authenticated handshake.');
        }
    }

    /**
     * @param  array<string, \Closure(string, string, float, int): array<string, mixed>>  $workers
     */
    public function start(array $workers, float $deadline, int $maxFrameBytes, int $cancelGraceMilliseconds): ParallelStreamSession
    {
        if (! function_exists('proc_open') || ! function_exists('stream_socket_server') || ! function_exists('stream_select')) {
            throw new SwarmException('Live parallel streaming requires proc_open and PHP stream socket/select support. Use prompt() or run in an environment with the process transport.');
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (! is_resource($server)) {
            throw new SwarmException("Live parallel streaming could not open its loopback transport [{$errorCode}: {$errorMessage}]. Use prompt() or enable loopback process transport.");
        }
        stream_set_blocking($server, false);

        $endpoint = stream_socket_get_name($server, false);
        if (! is_string($endpoint)) {
            fclose($server);
            throw new SwarmException('Live parallel streaming could not resolve its loopback transport address.');
        }

        $token = bin2hex(random_bytes(32));
        // An argv command avoids a shell wrapper, so stop() signals and reaps the
        // PHP worker itself instead of orphaning it on cancellation.
        $command = [php_binary(), artisan_binary(), 'invoke-serialized-closure'];
        $running = [];

        try {
            foreach ($workers as $branchId => $worker) {
                $task = static fn (): array => $worker($endpoint, $token, $deadline, $maxFrameBytes);
                $remainingSeconds = max(1, (int) ceil(($deadline - (float) hrtime(true)) / 1_000_000_000));
                $running[$branchId] = $this->processes->newPendingProcess()
                    ->path(base_path())
                    ->env([
                        '__LARAVEL_CONTEXT' => json_encode(Context::dehydrate()),
                        'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
                    ])
                    ->timeout($remainingSeconds)
                    ->command($command)
                    ->start();
            }
        } catch (\Throwable $exception) {
            fclose($server);
            foreach ($running as $process) {
                if ($process->running()) {
                    $process->stop($cancelGraceMilliseconds / 1000);
                }
            }
            throw $exception;
        }

        return new ParallelStreamSession(
            server: $server,
            processes: $running,
            branchIds: array_keys($workers),
            token: $token,
            maxFrameBytes: $maxFrameBytes,
            deadline: $deadline,
            cancelGraceMilliseconds: $cancelGraceMilliseconds,
        );
    }

    /** @return array{branch_id: string, terminal_sent: true} */
    private static function runReadinessWorker(string $endpoint, string $token, float $deadline, int $maxFrameBytes, string $branchId): array
    {
        $container = Container::getInstance();
        $container->make(ParallelAgentResolver::class);
        $container->make(ParallelStreamBranchWorker::class);

        $timeout = max(0.001, ($deadline - (float) hrtime(true)) / 1_000_000_000);
        $socket = @stream_socket_client('tcp://'.$endpoint, $errorCode, $errorMessage, $timeout);
        if (! is_resource($socket)) {
            throw new SwarmException("Parallel stream readiness worker could not connect to loopback transport [{$errorCode}: {$errorMessage}].");
        }
        stream_set_blocking($socket, false);

        $send = static function (string $type, array $payload = []) use ($socket, $token, $branchId, $deadline, $maxFrameBytes): void {
            ParallelStreamProtocol::writeFrame($socket, [
                'v' => ParallelStreamProtocol::VERSION,
                'token' => $token,
                'branch_id' => $branchId,
                'type' => $type,
                'payload' => $payload,
            ], $maxFrameBytes, $deadline);
            ParallelStreamProtocol::awaitAcknowledgement($socket, $deadline);
        };

        try {
            $send('hello');
            $send('terminal', ['ok' => true]);
        } finally {
            fclose($socket);
        }

        return ['branch_id' => $branchId, 'terminal_sent' => true];
    }
}
