<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidenceLimits;
use BuiltByBerry\LaravelSwarm\Responses\SwarmCitation;
use Illuminate\Config\Repository;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;

/** @internal */
final class NativeCitationEvidence
{
    public function __construct(private CitationEvidenceLimits $limits) {}

    /** @param array{max_count: int, max_bytes: int} $settings */
    public static function forConcurrentWorker(array $settings): self
    {
        return new self(new CitationEvidenceLimits(new Repository(['swarm' => ['citations' => $settings]])));
    }

    public function response(AgentResponse|StreamedAgentResponse $response, string $runId, int $stepIndex, string $agentClass, ?string $nodeId = null): CitationEvidence
    {
        $evidence = CitationEvidence::available();
        foreach ($response->meta->citations as $source) {
            $part = $this->source($source, $runId, $stepIndex, $agentClass, $nodeId, $response->invocationId);
            $evidence = $this->limits->apply(CitationEvidence::combine([$evidence, $part]));
            if (in_array('limit', $evidence->reasons, true)) {
                break;
            }
        }

        return $evidence;
    }

    public function event(Citation $event, string $runId, int $stepIndex, string $agentClass): CitationEvidence
    {
        return $this->limits->apply($this->source($event->citation, $runId, $stepIndex, $agentClass,
            null, $event->invocationId, $event->messageId, $event->id, $event->timestamp));
    }

    public function append(CitationEvidence $existing, CitationEvidence $event): CitationEvidence
    {
        if (in_array('limit', $existing->reasons, true)) {
            return $existing;
        }
        foreach ($event->items as $item) {
            foreach ($existing->items as $old) {
                if ($item->eventId !== null && $item->eventId === $old->eventId
                    && $item->invocationId === $old->invocationId && $item->runId === $old->runId
                    && $item->stepIndex === $old->stepIndex) {
                    return $existing;
                }
            }
        }

        return $this->limits->apply(CitationEvidence::combine([$existing, $event]));
    }

    public function reconcile(CitationEvidence $events, CitationEvidence $terminal): CitationEvidence
    {
        $matched = [];
        $eventItems = $events->items;
        $extra = [];
        foreach ($terminal->items as $item) {
            foreach ($events->items as $index => $event) {
                if (! isset($matched[$index]) && $item->url === $event->url && $item->title === $event->title
                    && $item->startIndex === $event->startIndex && $item->endIndex === $event->endIndex
                    && $item->runId === $event->runId && $item->stepIndex === $event->stepIndex
                    && ($item->invocationId === null || $event->invocationId === null || $item->invocationId === $event->invocationId)) {
                    $matched[$index] = true;
                    $eventItems[$index] = new SwarmCitation($event->url, $event->title, $event->runId,
                        $event->stepIndex, $event->agentClass, $event->startIndex, $event->endIndex,
                        $event->nodeId ?? $item->nodeId, $event->invocationId ?? $item->invocationId,
                        $event->messageId ?? $item->messageId, $event->eventId ?? $item->eventId,
                        $event->timestamp ?? $item->timestamp);

                    continue 2;
                }
            }
            $extra[] = $item;
        }

        return $this->limits->apply(CitationEvidence::combine([
            new CitationEvidence(array_values($eventItems), $events->status, $events->reasons),
            new CitationEvidence($extra, $terminal->status, $terminal->reasons),
        ]));
    }

    private function source(mixed $source, string $runId, int $stepIndex, string $agentClass,
        ?string $nodeId = null, ?string $invocationId = null, ?string $messageId = null,
        ?string $eventId = null, ?int $timestamp = null): CitationEvidence
    {
        if (! $source instanceof UrlCitation) {
            return new CitationEvidence([], CitationEvidence::PARTIAL, ['unsupported_type']);
        }

        return new CitationEvidence([new SwarmCitation($source->url, $source->title, $runId,
            $stepIndex, $agentClass, $source->startIndex, $source->endIndex, $nodeId,
            $invocationId, $messageId, $eventId, $timestamp)], CitationEvidence::AVAILABLE);
    }
}
