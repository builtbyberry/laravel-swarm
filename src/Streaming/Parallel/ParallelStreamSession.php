<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Runners\ConcurrentAgentResult;
use Illuminate\Process\InvokedProcess;

/** @internal */
final class ParallelStreamSession
{
    public ?string $failureBranchId = null;

    /** @var array<string, resource> */
    private array $clients = [];

    /** @var array<string, array<string, mixed>> */
    private array $outcomes = [];

    private ParallelStreamHandshakeRegistry $handshakes;

    /**
     * @param  resource  $server
     * @param  array<string, InvokedProcess>  $processes
     * @param  list<string>  $branchIds
     */
    public function __construct(
        private $server,
        private array $processes,
        private array $branchIds,
        private string $token,
        private int $maxFrameBytes,
        private float $deadline,
        private int $cancelGraceMilliseconds,
    ) {
        $this->handshakes = new ParallelStreamHandshakeRegistry($token, $branchIds);
    }

    /**
     * Yield authenticated event payloads; return terminal outcome rows keyed by branch.
     *
     * @return \Generator<int, array{branch_id: string, payload: array<string, mixed>}, mixed, array<string, array<string, mixed>>>
     */
    public function events(): \Generator
    {
        $successful = false;

        try {
            while (count($this->outcomes) < count($this->branchIds)) {
                $this->assertBeforeDeadline();
                $this->acceptReadyConnections();

                $read = array_values($this->clients);
                if ($read === []) {
                    $this->assertWorkersAlive();
                    usleep(1_000);

                    continue;
                }

                $write = [];
                $except = [];
                $selected = @stream_select($read, $write, $except, 0, 100_000);
                if ($selected === false) {
                    throw new SwarmException('Parallel stream transport could not poll branch sockets.');
                }
                if ($selected === 0) {
                    $this->assertWorkersAlive();

                    continue;
                }

                foreach ($read as $socket) {
                    $branchId = array_search($socket, $this->clients, true);
                    if (! is_string($branchId)) {
                        throw new SwarmException('Parallel stream transport lost a branch socket identity.');
                    }

                    $this->failureBranchId = $branchId;
                    $frame = ParallelStreamProtocol::readFrame($socket, $this->maxFrameBytes, $this->deadline);
                    $this->validateFrame($frame, $branchId);
                    $type = $frame['type'] ?? null;
                    $payload = $frame['payload'] ?? null;
                    if (! is_array($payload)) {
                        throw new SwarmException("Parallel stream branch [{$branchId}] sent a frame without an object payload.");
                    }

                    if ($type === 'event') {
                        yield ['branch_id' => $branchId, 'payload' => $payload];
                        ParallelStreamProtocol::acknowledge($socket, $this->deadline);
                        $this->failureBranchId = null;

                        continue;
                    }

                    if ($type !== 'terminal') {
                        throw new SwarmException("Parallel stream branch [{$branchId}] sent an unsupported frame type.");
                    }

                    ParallelStreamProtocol::acknowledge($socket, $this->deadline);
                    $this->outcomes[$branchId] = $payload;
                    fclose($socket);
                    unset($this->clients[$branchId]);

                    if (($payload['ok'] ?? null) !== true) {
                        $failure = is_array($payload['failure'] ?? null) ? $payload['failure'] : [];
                        ConcurrentAgentResult::throwFailureDescriptor($failure);
                    }

                    $this->failureBranchId = null;
                }
            }

            $this->validateProcesses();
            $successful = true;

            return $this->outcomes;
        } finally {
            $this->cleanup(! $successful);
        }
    }

    private function acceptReadyConnections(): void
    {
        $read = [$this->server];
        $write = [];
        $except = [];
        if (@stream_select($read, $write, $except, 0, 0) !== 1) {
            return;
        }

        $socket = @stream_socket_accept($this->server, 0);
        if (! is_resource($socket)) {
            return;
        }

        stream_set_blocking($socket, false);
        $handshakeDeadline = min($this->deadline, (float) hrtime(true) + 1_000_000_000);

        try {
            $frame = ParallelStreamProtocol::readFrame($socket, $this->maxFrameBytes, $handshakeDeadline);
            $branchId = $this->handshakes->accept($frame);

            $this->clients[$branchId] = $socket;
            ParallelStreamProtocol::acknowledge($socket, $handshakeDeadline);
        } catch (\Throwable $exception) {
            fclose($socket);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $frame */
    private function validateFrame(array $frame, string $branchId): void
    {
        if (($frame['v'] ?? null) !== ParallelStreamProtocol::VERSION
            || ! hash_equals($this->token, is_string($frame['token'] ?? null) ? $frame['token'] : '')
            || ($frame['branch_id'] ?? null) !== $branchId) {
            throw new SwarmException("Parallel stream transport rejected an invalid frame for branch [{$branchId}].");
        }
    }

    private function assertWorkersAlive(): void
    {
        foreach ($this->processes as $branchId => $process) {
            if (! isset($this->outcomes[$branchId]) && ! $process->running()) {
                $result = $process->wait();
                throw new SwarmException("Parallel stream branch [{$branchId}] exited before an authenticated terminal outcome (exit {$result->exitCode()}).");
            }
        }
    }

    private function validateProcesses(): void
    {
        foreach ($this->processes as $branchId => $process) {
            $result = $process->wait();
            if ($result->failed()) {
                throw new SwarmException("Parallel stream branch [{$branchId}] process failed with exit code [{$result->exitCode()}].");
            }

            $envelope = json_decode($result->output(), true);
            $value = is_array($envelope) && ($envelope['successful'] ?? null) === true
                ? unserialize((string) ($envelope['result'] ?? ''))
                : null;
            if (! is_array($value)
                || ($value['branch_id'] ?? null) !== $branchId
                || ($value['terminal_sent'] ?? null) !== true) {
                throw new SwarmException("Parallel stream branch [{$branchId}] returned an invalid process result envelope.");
            }
        }
    }

    private function assertBeforeDeadline(): void
    {
        if ((float) hrtime(true) >= $this->deadline) {
            throw new SwarmTimeoutException('The swarm exceeded its configured timeout while multiplexing parallel streams.');
        }
    }

    private function cleanup(bool $cancel): void
    {
        foreach ($this->clients as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->clients = [];

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        if ($cancel) {
            foreach ($this->processes as $process) {
                if ($process->running()) {
                    $process->stop($this->cancelGraceMilliseconds / 1000);
                }
            }
        }
    }
}
