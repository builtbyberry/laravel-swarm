<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\UserMessage;

final class NativeInputManifest
{
    public const VERSION = 1;

    /**
     * @param  list<File>  $attachments
     * @param  list<NativeInputRecipient>  $recipients
     * @param  array<int, string>  $attachmentHashes
     * @param  list<int>  $ownedAttachmentIndexes
     */
    public function __construct(
        public string $text,
        public array $attachments,
        public array $recipients,
        public array $attachmentHashes = [],
        public array $ownedAttachmentIndexes = [],
    ) {}

    public function messageFor(string $recipient, string $topologyText): string|UserMessage
    {
        return $this->invocationFor($recipient, $topologyText)->prompt;
    }

    public function invocationFor(string $recipient, string $topologyText): NativeAgentInvocation
    {
        $selection = null;

        foreach ($this->recipients as $candidate) {
            if ($candidate->recipient === $recipient) {
                $selection = $candidate;
                break;
            }
        }

        if ($selection === null) {
            return new NativeAgentInvocation($topologyText);
        }

        $attachments = $selection->attachments === null
            ? $this->attachments
            : array_values(array_intersect_key($this->attachments, array_flip($selection->attachments)));

        foreach ($attachments as $attachment) {
            $index = array_search($attachment, $this->attachments, true);
            $expected = is_int($index) ? ($this->attachmentHashes[$index] ?? null) : null;
            if (is_string($expected) && method_exists($attachment, 'content')) {
                $actual = hash('sha256', (string) $attachment->content());
                if (! hash_equals($expected, $actual)) {
                    throw new SwarmException("Native attachment [{$index}] failed its content identity check.");
                }
            }
        }

        return new NativeAgentInvocation(
            prompt: new UserMessage(
                $selection->textSource === 'original' ? $this->text : $topologyText,
                $attachments,
            ),
            provider: $selection->provider,
            model: $selection->model,
            timeout: $selection->timeout,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'text' => $this->text,
            'attachments' => array_map(function (File $file, int $index): array {
                if (! $file instanceof Arrayable) {
                    throw new SwarmException('Native attachments must provide Laravel AI array serialization.');
                }

                $payload = $file->toArray();
                if (isset($this->attachmentHashes[$index])) {
                    $payload['swarm_content_sha256'] = $this->attachmentHashes[$index];
                }
                if (in_array($index, $this->ownedAttachmentIndexes, true)) {
                    $payload['swarm_owned'] = true;
                }

                return $payload;
            }, $this->attachments, array_keys($this->attachments)),
            'recipients' => array_map(static fn (NativeInputRecipient $recipient): array => $recipient->toArray(), $this->recipients),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $attachments = [];
        $hashes = [];
        $owned = [];
        foreach ($payload['attachments'] ?? [] as $attachment) {
            if (! is_array($attachment) || ($file = File::fromArray($attachment)) === null) {
                throw new SwarmException('Native input envelope contains an unsupported attachment descriptor.');
            }

            $index = count($attachments);
            $attachments[] = $file;
            if (is_string($attachment['swarm_content_sha256'] ?? null)) {
                $hashes[$index] = $attachment['swarm_content_sha256'];
            }
            if (($attachment['swarm_owned'] ?? false) === true) {
                $owned[] = $index;
            }
        }

        foreach ($payload['recipients'] ?? [] as $recipient) {
            if (! is_array($recipient)) {
                throw new SwarmException('Native input envelope contains an invalid recipient descriptor.');
            }
        }

        return new self(
            text: (string) ($payload['text'] ?? ''),
            attachments: $attachments,
            recipients: array_map(
                static fn (array $recipient): NativeInputRecipient => NativeInputRecipient::fromArray($recipient),
                array_values($payload['recipients'] ?? []),
            ),
            attachmentHashes: $hashes,
            ownedAttachmentIndexes: $owned,
        );
    }
}
