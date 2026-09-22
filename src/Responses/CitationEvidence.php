<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class CitationEvidence implements Arrayable, JsonSerializable
{
    public const AVAILABLE = 'available';

    public const PARTIAL = 'partial';

    public const REDACTED = 'redacted';

    public const OMITTED = 'omitted';

    public const UNAVAILABLE = 'unavailable';

    public const UNKNOWN = 'unknown';

    private const REASONS = ['unsupported_type', 'limit', 'malformed', 'decrypt_failed',
        'contributing_unknown', 'contributing_redacted', 'contributing_omitted', 'contributing_unavailable'];

    /**
     * @param  list<SwarmCitation>  $items
     * @param  list<string>  $reasons
     */
    public function __construct(
        public array $items = [],
        public string $status = self::UNKNOWN,
        public array $reasons = [],
    ) {
        if (! in_array($status, [self::AVAILABLE, self::PARTIAL, self::REDACTED, self::OMITTED, self::UNAVAILABLE, self::UNKNOWN], true)
            || (! in_array($status, [self::AVAILABLE, self::PARTIAL], true) && $items !== [])
            || ($status === self::AVAILABLE && $reasons !== [])
            || ($status === self::PARTIAL && $reasons === [])
            || ! array_is_list($items) || ! array_is_list($reasons)) {
            throw new InvalidArgumentException('Invalid citation evidence state.');
        }
        foreach ($reasons as $reason) {
            if (! is_string($reason) || ! in_array($reason, self::REASONS, true)) {
                throw new InvalidArgumentException('Invalid citation evidence reason.');
            }
        }
        foreach ($items as $item) {
            if (! $item instanceof SwarmCitation) {
                throw new InvalidArgumentException('Invalid citation evidence item.');
            }
        }
    }

    public static function available(): self
    {
        return new self([], self::AVAILABLE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['citation_status' => $this->status, 'citation_reasons' => $this->reasons];
        if ($this->status !== self::OMITTED) {
            $data['citations'] = array_map(static fn (SwarmCitation $item): array => $item->toArray(), $this->items);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('citation_status', $data)) {
            return new self;
        }
        try {
            if (! is_string($data['citation_status']) || ! is_array($data['citation_reasons'] ?? null)
                || ($data['citation_status'] !== self::OMITTED && ! is_array($data['citations'] ?? null))
                || (array_key_exists('citations', $data) && ! is_array($data['citations']))) {
                throw new InvalidArgumentException;
            }
            $items = [];
            foreach ($data['citations'] ?? [] as $item) {
                if (! is_array($item)) {
                    throw new InvalidArgumentException;
                }
                $items[] = SwarmCitation::fromArray($item);
            }

            return new self($items, $data['citation_status'], array_values($data['citation_reasons']));
        } catch (InvalidArgumentException) {
            return new self([], self::UNAVAILABLE, ['malformed']);
        }
    }

    /** @param iterable<self> $parts */
    public static function combine(iterable $parts): self
    {
        $items = $statuses = $reasons = [];
        foreach ($parts as $part) {
            array_push($items, ...$part->items);
            $statuses[] = $part->status;
            array_push($reasons, ...$part->reasons);
        }
        $statuses = array_values(array_unique($statuses));
        if ($statuses === [] || $statuses === [self::AVAILABLE]) {
            return new self($items, self::AVAILABLE);
        }
        if (count($statuses) === 1 && $statuses[0] !== self::PARTIAL) {
            return new self([], $statuses[0], array_values(array_unique($reasons)));
        }
        foreach ($statuses as $status) {
            if (in_array($status, [self::UNKNOWN, self::REDACTED, self::OMITTED, self::UNAVAILABLE], true)) {
                $reasons[] = 'contributing_'.$status;
            }
        }

        return new self($items, self::PARTIAL, array_values(array_unique($reasons)));
    }

    public function withNodeId(string $nodeId): self
    {
        return new self(array_map(static fn (SwarmCitation $item): SwarmCitation => $item->withNodeId($nodeId), $this->items), $this->status, $this->reasons);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
