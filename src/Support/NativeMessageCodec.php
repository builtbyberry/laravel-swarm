<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/** @internal */
final class NativeMessageCodec
{
    /**
     * @param  array<int, array{sha256?: string, owned?: bool, mime?: string}>  $attachmentMetadata
     * @return array<string, mixed>
     */
    public static function encode(Message $message, array $attachmentMetadata = []): array
    {
        if ($message instanceof UserMessage) {
            return [
                'type' => 'user',
                'content' => $message->content,
                'attachments' => $message->attachments->map(function (mixed $attachment, int $index) use ($attachmentMetadata): array {
                    if (! $attachment instanceof File || ! $attachment instanceof Arrayable) {
                        throw new SwarmException('Native withMessages user attachments must be reconstructible Laravel AI files. Closures, streams, and container services cannot cross a worker boundary.');
                    }

                    $payload = PlainData::array($attachment->toArray(), 'native withMessages attachment');
                    if (is_string($attachmentMetadata[$index]['mime'] ?? null) && $attachmentMetadata[$index]['mime'] !== '') {
                        $payload['swarm_mime'] = $attachmentMetadata[$index]['mime'];
                    }
                    if (is_string($attachmentMetadata[$index]['sha256'] ?? null)) {
                        $payload['swarm_content_sha256'] = $attachmentMetadata[$index]['sha256'];
                    }
                    if (($attachmentMetadata[$index]['owned'] ?? false) === true) {
                        $payload['swarm_owned'] = true;
                    }

                    return $payload;
                })->values()->all(),
            ];
        }

        if ($message instanceof AssistantMessage) {
            return [
                'type' => 'assistant',
                'content' => $message->content,
                'tool_calls' => $message->toolCalls->map(
                    static fn (ToolCall $call): array => PlainData::array($call->toArray(), 'native withMessages tool call'),
                )->values()->all(),
                'replay_blocks' => PlainData::array($message->replayBlocks, 'native withMessages provider replay blocks'),
                'replay_blocks_provider' => $message->replayBlocksProvider,
            ];
        }

        if ($message instanceof ToolResultMessage) {
            return [
                'type' => 'tool_result',
                'tool_results' => $message->toolResults->map(
                    static fn (ToolResult $result): array => PlainData::array($result->toArray(), 'native withMessages tool result'),
                )->values()->all(),
            ];
        }

        return [
            'type' => 'message',
            'role' => $message->role->value,
            'content' => $message->content,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function decode(array $payload): Message
    {
        return match ($payload['type'] ?? null) {
            'user' => self::decodeUser($payload),
            'assistant' => self::decodeAssistant($payload),
            'tool_result' => self::decodeToolResults($payload),
            'message' => self::decodeMessage($payload),
            default => throw new SwarmException('Native withMessages descriptor has an unsupported message type.'),
        };
    }

    /** @param array<string, mixed> $payload */
    protected static function decodeUser(array $payload): UserMessage
    {
        $attachments = [];
        foreach ($payload['attachments'] ?? [] as $attachment) {
            if (! is_array($attachment) || ($file = File::fromArray($attachment)) === null) {
                throw new SwarmException('Native withMessages contains an unsupported attachment descriptor.');
            }
            if (is_string($attachment['swarm_mime'] ?? null) && $attachment['swarm_mime'] !== '') {
                $file->withMimeType($attachment['swarm_mime']);
            }
            if (isset($attachment['swarm_content_sha256'])) {
                $expected = $attachment['swarm_content_sha256'];
                if (! is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1 || ! method_exists($file, 'content')) {
                    throw new SwarmException('Native withMessages contains an invalid attachment content identity.');
                }
                $content = (string) $file->content();
                if (! hash_equals($expected, hash('sha256', $content))) {
                    throw new SwarmException('Native withMessages attachment failed its content identity check.');
                }
                $file = self::materialize($file, $content);
            }
            $attachments[] = $file;
        }

        return new UserMessage(self::content($payload), $attachments);
    }

    /** @param array<string, mixed> $payload */
    protected static function decodeAssistant(array $payload): AssistantMessage
    {
        $calls = [];
        foreach ($payload['tool_calls'] ?? [] as $call) {
            if (! is_array($call)) {
                throw new SwarmException('Native withMessages contains an invalid tool-call descriptor.');
            }
            $calls[] = ToolCall::fromArray(PlainData::array($call, 'native withMessages tool call'));
        }

        $blocks = $payload['replay_blocks'] ?? [];
        $provider = $payload['replay_blocks_provider'] ?? null;
        if (! is_array($blocks) || ($provider !== null && ! is_string($provider))) {
            throw new SwarmException('Native withMessages contains invalid provider replay state.');
        }

        return new AssistantMessage(
            self::content($payload),
            new Collection($calls),
            PlainData::array($blocks, 'native withMessages provider replay blocks'),
            $provider,
        );
    }

    /** @param array<string, mixed> $payload */
    protected static function decodeToolResults(array $payload): ToolResultMessage
    {
        $results = [];
        foreach ($payload['tool_results'] ?? [] as $result) {
            if (! is_array($result)) {
                throw new SwarmException('Native withMessages contains an invalid tool-result descriptor.');
            }
            $results[] = ToolResult::fromArray(PlainData::array($result, 'native withMessages tool result'));
        }

        return new ToolResultMessage(new Collection($results));
    }

    /** @param array<string, mixed> $payload */
    protected static function decodeMessage(array $payload): Message
    {
        $role = $payload['role'] ?? null;
        $content = $payload['content'] ?? null;
        if (! is_string($role) || ($content !== null && ! is_string($content))) {
            throw new SwarmException('Native withMessages contains an invalid message descriptor.');
        }

        return new Message($role, $content);
    }

    /** @param array<string, mixed> $payload */
    protected static function content(array $payload): string
    {
        if (! is_string($payload['content'] ?? null)) {
            throw new SwarmException('Native withMessages message content must be a string.');
        }

        return $payload['content'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{sha256?: string, owned?: bool, mime?: string}>
     */
    public static function attachmentMetadata(array $payload): array
    {
        if (($payload['type'] ?? null) !== 'user' || ! is_array($payload['attachments'] ?? null)) {
            return [];
        }

        $metadata = [];
        foreach ($payload['attachments'] as $index => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $row = [];
            if (is_string($attachment['swarm_content_sha256'] ?? null)) {
                $row['sha256'] = $attachment['swarm_content_sha256'];
            }
            if (($attachment['swarm_owned'] ?? false) === true) {
                $row['owned'] = true;
            }
            if (is_string($attachment['swarm_mime'] ?? null) && $attachment['swarm_mime'] !== '') {
                $row['mime'] = $attachment['swarm_mime'];
            }
            if ($row !== []) {
                $metadata[(int) $index] = $row;
            }
        }

        return $metadata;
    }

    protected static function materialize(File $file, string $content): File
    {
        $mime = $file->mimeType();
        $base64 = base64_encode($content);
        $materialized = match (true) {
            str_contains($file::class, 'Image') => new Base64Image($base64, $mime),
            str_contains($file::class, 'Document') => new Base64Document($base64, $mime),
            str_contains($file::class, 'Audio') => new Base64Audio($base64, $mime),
            str_contains($file::class, 'Video') => new Base64Video($base64, $mime),
            default => $file,
        };
        $materialized->as($file->name());

        return $materialized;
    }
}
