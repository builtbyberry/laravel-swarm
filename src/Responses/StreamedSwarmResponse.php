<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Illuminate\Support\Collection;

class StreamedSwarmResponse extends SwarmResponse
{
    /**
     * @param  Collection<int, SwarmStreamEvent>  $events
     */
    public function __construct(
        SwarmResponse $response,
        public readonly Collection $events,
    ) {
        parent::__construct(
            output: $response->output,
            steps: $response->steps,
            usage: $response->usage,
            context: $response->context,
            artifacts: $response->artifacts,
            metadata: $response->metadata,
        );
    }

    /**
     * @param  Collection<int, SwarmStreamEvent>  $events
     */
    public static function fromEvents(string $runId, Collection $events): self
    {
        $streamEnd = $events->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStreamEnd);
        $stepEnds = $events->whereInstanceOf(SwarmStepEnd::class)->values();

        // Stream events carry nullable output once a CaptureDecision::Skip omits
        // a field; the live SwarmResponse/SwarmStep surface requires a string, so
        // omitted values are coerced to ''. To keep the Skip-vs-empty distinction
        // on this convenience surface, set metadata['output_skipped'] = true
        // wherever the resolved output was an explicit null (a Skip omission) —
        // never where combine() yields a genuinely empty '' output.
        $resolvedOutput = $streamEnd instanceof SwarmStreamEnd ? $streamEnd->output : SwarmTextDelta::combine($events);

        $response = new SwarmResponse(
            output: $resolvedOutput ?? '',
            steps: $stepEnds
                ->map(fn (SwarmStepEnd $event): SwarmStep => new SwarmStep(
                    agentClass: $event->agentClass,
                    input: '',
                    output: $event->output ?? '',
                    metadata: array_merge($event->metadata, [
                        'index' => $event->stepIndex,
                        'duration_ms' => $event->durationMs,
                    ], $event->output === null ? ['output_skipped' => true] : []),
                ))
                ->all(),
            usage: $streamEnd instanceof SwarmStreamEnd ? $streamEnd->usage : [],
            metadata: array_merge(
                ['run_id' => $runId],
                $streamEnd instanceof SwarmStreamEnd ? $streamEnd->metadata : [],
                $resolvedOutput === null ? ['output_skipped' => true] : [],
            ),
        );

        return new self($response, $events);
    }
}
