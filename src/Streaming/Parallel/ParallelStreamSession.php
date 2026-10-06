<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Runners\ConcurrentAgentResult;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;

/** @internal */
final class ParallelStreamSession
{
    private const MAX_ACCEPTS_PER_TICK = 32;

    private const MAX_UNAUTHENTICATED_ALLOWANCE = 64;

    public ?string $failureBranchId = null;

    /** @var array<string, resource> */
    private array $clients = [];

    /** @var array<int, array{socket: resource, bytes: string, deadline: float}> */
    private array $pendingClients = [];

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
     * @return \Generator<int, array{branch_id: string, payload: array<string, mixed>, terminal?: true}, mixed, array<string, array<string, mixed>>>
     */
    public function events(): \Generator
    {
        $successful = false;

        try {
            while (count($this->outcomes) < count($this->branchIds)) {
                $this->assertBeforeDeadline();
                $this->acceptReadyConnections();
                $this->expirePendingConnections();

                $read = [
                    ...array_values($this->clients),
                    ...array_map(static fn (array $pending) => $pending['socket'], $this->pendingClients),
                ];
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
                    $pendingId = get_resource_id($socket);
                    if (isset($this->pendingClients[$pendingId])) {
                        $this->readPendingHandshake($pendingId);

                        continue;
                    }

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

                    if (($payload['ok'] ?? null) !== true) {
                        yield ['branch_id' => $branchId, 'payload' => $payload, 'terminal' => true];
                        ParallelStreamProtocol::acknowledge($socket, $this->deadline);
                        $this->outcomes[$branchId] = $payload;
                        fclose($socket);
                        unset($this->clients[$branchId]);
                        $failure = is_array($payload['failure'] ?? null) ? $payload['failure'] : [];
                        ConcurrentAgentResult::throwFailureDescriptor($failure);
                    }

                    yield ['branch_id' => $branchId, 'payload' => $payload, 'terminal' => true];
                    ParallelStreamProtocol::acknowledge($socket, $this->deadline);
                    $this->outcomes[$branchId] = $payload;
                    fclose($socket);
                    unset($this->clients[$branchId]);

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
        $accepted = 0;

        while ($accepted < self::MAX_ACCEPTS_PER_TICK) {
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
            $accepted++;

            $remainingBranches = max(0, count($this->branchIds) - count($this->clients) - count($this->outcomes));
            $unauthenticatedAllowance = max(8, min(self::MAX_UNAUTHENTICATED_ALLOWANCE, count($this->branchIds)));
            $pendingLimit = $remainingBranches + $unauthenticatedAllowance;
            if (count($this->pendingClients) >= $pendingLimit) {
                fclose($socket);

                continue;
            }

            $this->pendingClients[get_resource_id($socket)] = [
                'socket' => $socket,
                'bytes' => '',
                'deadline' => min($this->deadline, (float) hrtime(true) + 1_000_000_000),
            ];
        }
    }

    private function readPendingHandshake(int $pendingId): void
    {
        $pending = $this->pendingClients[$pendingId];
        $socket = $pending['socket'];
        $chunk = @fread($socket, max(1, $this->maxFrameBytes + 5));
        if (! is_string($chunk) || $chunk === '') {
            if (feof($socket)) {
                $this->discardPending($pendingId);
            }

            return;
        }

        $bytes = $pending['bytes'].$chunk;
        if (strlen($bytes) < 4) {
            $this->pendingClients[$pendingId]['bytes'] = $bytes;

            return;
        }

        $length = unpack('Nlength', substr($bytes, 0, 4))['length'] ?? 0;
        if (! is_int($length) || $length < 2 || $length > $this->maxFrameBytes) {
            $this->discardPending($pendingId);

            return;
        }
        if (strlen($bytes) < 4 + $length) {
            $this->pendingClients[$pendingId]['bytes'] = $bytes;

            return;
        }

        try {
            $frame = json_decode(substr($bytes, 4, $length), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->discardPending($pendingId);

            return;
        }
        if (! is_array($frame)
            || strlen($bytes) !== 4 + $length
            || ($frame['v'] ?? null) !== ParallelStreamProtocol::VERSION
            || ! hash_equals($this->token, is_string($frame['token'] ?? null) ? $frame['token'] : '')
            || ($frame['type'] ?? null) !== 'hello'
            || ! in_array($frame['branch_id'] ?? null, $this->branchIds, true)) {
            $this->discardPending($pendingId);

            return;
        }

        // From this point the peer proved possession of the stream token and a
        // declared branch identity, so duplicate/protocol failures are fatal.
        $branchId = $this->handshakes->accept($frame);

        unset($this->pendingClients[$pendingId]);
        $this->clients[$branchId] = $socket;
        ParallelStreamProtocol::acknowledge($socket, $pending['deadline']);
    }

    private function expirePendingConnections(): void
    {
        $now = (float) hrtime(true);
        foreach ($this->pendingClients as $pendingId => $pending) {
            if ($now >= $pending['deadline']) {
                $this->discardPending($pendingId);
            }
        }
    }

    private function discardPending(int $pendingId): void
    {
        $socket = $this->pendingClients[$pendingId]['socket'] ?? null;
        if (is_resource($socket)) {
            fclose($socket);
        }
        unset($this->pendingClients[$pendingId]);
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
                throw new SwarmException("Parallel stream branch [{$branchId}] exited before an authenticated terminal outcome (exit {$result->exitCode()}; {$this->diagnostic($result)}).");
            }
        }
    }

    private function validateProcesses(): void
    {
        foreach ($this->processes as $branchId => $process) {
            $result = $process->wait();
            if ($result->failed()) {
                throw new SwarmException("Parallel stream branch [{$branchId}] process failed with exit code [{$result->exitCode()}]; {$this->diagnostic($result)}.");
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

    private function diagnostic(ProcessResult $result): string
    {
        $raw = trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output();
        $digest = hash('sha256', $raw);

        return "diagnostic withheld (sha256:{$digest})";
    }

    private function cleanup(bool $cancel): void
    {
        foreach ($this->clients as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->clients = [];

        foreach ($this->pendingClients as $pending) {
            if (is_resource($pending['socket'])) {
                fclose($pending['socket']);
            }
        }
        $this->pendingClients = [];

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
