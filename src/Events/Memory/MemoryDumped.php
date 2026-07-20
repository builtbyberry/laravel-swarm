<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryDumpCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver;

/**
 * Fired after a successful `swarm:memory:dump` invocation.
 *
 * Dispatched once per successful export by {@see SwarmMemoryDumpCommand},
 * carrying what was exported so app-level audit listeners can record operator
 * extraction of memory — the moment a run or conversation's memory leaves the
 * system as an audit packet / legal handoff.
 *
 * Failed dumps (missing id, ambiguous id, invalid options, configuration
 * errors) do NOT dispatch — this event is the evidence of a completed export,
 * not an attempted one.
 *
 * Payload shape:
 *
 * - `subjectType`      — `run` or `conversation`, the resolved interpretation
 *                        of the id the operator passed.
 * - `subjectId`        — the id argument the operator passed.
 * - `format`           — the resolved output format (`json` or `ndjson`).
 * - `includeSnapshots` — whether full snapshot payloads were embedded.
 * - `entryCount`       — number of memory entries exported.
 * - `snapshotCount`    — number of snapshot rows exported.
 * - `runsExpanded`     — for a conversation subject, whether the bound
 *                        {@see ConversationRunResolver}
 *                        expanded the conversation into runs. Always false for
 *                        a run subject.
 *
 * Listeners doing audit-trail capture will typically pair this event with the
 * `command.memory.dump` audit category emitted alongside it.
 */
final class MemoryDumped
{
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $format,
        public readonly bool $includeSnapshots,
        public readonly int $entryCount,
        public readonly int $snapshotCount,
        public readonly bool $runsExpanded,
    ) {}
}
