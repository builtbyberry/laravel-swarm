<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;

/** @internal */
final class ParallelProcessStreamTransport
{
    public function __construct(private ProcessFactory $processes) {}

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
        $command = ConsoleApplication::formatCommandString('invoke-serialized-closure');
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
}
