<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\UserMessage;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionProperty;

final class NativeInputManifest
{
    public const VERSION = 1;

    public const SETTINGS_VERSION = 2;

    /**
     * @param  list<File>  $attachments
     * @param  list<NativeInputRecipient>  $recipients
     * @param  array<int, string>  $attachmentHashes
     * @param  list<int>  $ownedAttachmentIndexes
     * @param  array<int, array<string, array{headers: array<string, string>, provider_options: array<string, mixed>}>>  $attachmentInvocationOptions
     * @param  list<string>  $consumedMessageConfigurationIds
     */
    public function __construct(
        public string $text,
        public array $attachments,
        public array $recipients,
        public array $attachmentHashes = [],
        public array $ownedAttachmentIndexes = [],
        public array $attachmentInvocationOptions = [],
        public array $consumedMessageConfigurationIds = [],
    ) {}

    public function messageFor(string $recipient, string $topologyText): string|UserMessage
    {
        return $this->invocationFor($recipient, $topologyText)->prompt;
    }

    public function invocationFor(string $recipient, string $topologyText, ?NativeAgentSettingsAttempt $attempt = null): NativeAgentInvocation
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

        $verified = [];
        foreach ($attachments as $attachment) {
            $index = array_search($attachment, $this->attachments, true);
            $expected = is_int($index) ? ($this->attachmentHashes[$index] ?? null) : null;
            if (is_string($expected) && method_exists($attachment, 'content')) {
                $content = (string) $attachment->content();
                $actual = hash('sha256', $content);
                if (! hash_equals($expected, $actual)) {
                    throw new SwarmException("Native attachment [{$index}] failed its content identity check.");
                }

                $attachment = NativeAttachmentMaterializer::fromVerifiedContent($attachment, $content);
            }

            $attachment = $this->applyInvocationOptions($attachment, $index, $selection);

            $verified[] = $attachment;
        }

        $configurationId = $selection->settingsId();
        $consumed = in_array($configurationId, $this->consumedMessageConfigurationIds, true)
            || $attempt?->consumed($configurationId) === true;
        $messages = $consumed ? [] : $selection->messages;
        if ($messages !== []) {
            $attempt?->stage($configurationId);
            if ($attempt === null) {
                $this->consumedMessageConfigurationIds[] = $configurationId;
                $this->consumedMessageConfigurationIds = array_values(array_unique($this->consumedMessageConfigurationIds));
            }
        }

        return new NativeAgentInvocation(
            prompt: new UserMessage(
                $selection->textSource === 'original' ? $this->text : $topologyText,
                $verified,
            ),
            provider: $selection->provider,
            model: $selection->model,
            timeout: $selection->timeout,
            tools: array_values(array_filter($selection->tools, static fn (mixed $tool): bool => $tool instanceof NativeAgentToolReference)),
            messages: $messages,
            conversation: $selection->conversation,
            configurationId: $selection->hasNativeSettings() ? $configurationId : null,
            toolsConfigured: $selection->toolsConfigured,
            messagesConfigured: $selection->messagesConfigured && (! $consumed || $selection->messages === []),
        );
    }

    public function formatVersion(): int
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->hasNativeSettings()) {
                return self::SETTINGS_VERSION;
            }
        }

        return self::VERSION;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->formatVersion(),
            'text' => $this->text,
            'attachments' => array_map(function (File $file, int $index): array {
                if (! $file instanceof Arrayable) {
                    throw new SwarmException('Native attachments must provide Laravel AI array serialization.');
                }

                $payload = $file->toArray();
                if (is_string($file->mimeType()) && $file->mimeType() !== '') {
                    $payload['swarm_mime'] = $file->mimeType();
                }
                if (isset($this->attachmentHashes[$index])) {
                    $payload['swarm_content_sha256'] = $this->attachmentHashes[$index];
                }
                if (in_array($index, $this->ownedAttachmentIndexes, true)) {
                    $payload['swarm_owned'] = true;
                }
                if (isset($this->attachmentInvocationOptions[$index])) {
                    $payload['swarm_invocation_options'] = $this->attachmentInvocationOptions[$index];
                }

                return $payload;
            }, $this->attachments, array_keys($this->attachments)),
            'recipients' => array_map(static fn (NativeInputRecipient $recipient): array => $recipient->toArray(), $this->recipients),
            'consumed_message_configuration_ids' => $this->consumedMessageConfigurationIds,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $attachments = [];
        $hashes = [];
        $owned = [];
        $invocationOptions = [];
        foreach ($payload['attachments'] ?? [] as $attachment) {
            if (! is_array($attachment) || ($file = File::fromArray($attachment)) === null) {
                throw new SwarmException('Native input envelope contains an unsupported attachment descriptor.');
            }

            $index = count($attachments);
            if (is_string($attachment['swarm_mime'] ?? null) && $attachment['swarm_mime'] !== '') {
                $file->withMimeType($attachment['swarm_mime']);
            }
            $attachments[] = $file;
            if (is_string($attachment['swarm_content_sha256'] ?? null)) {
                $hashes[$index] = $attachment['swarm_content_sha256'];
            }
            if (($attachment['swarm_owned'] ?? false) === true) {
                $owned[] = $index;
            }
            if (array_key_exists('swarm_invocation_options', $attachment)) {
                if (! is_array($attachment['swarm_invocation_options'])) {
                    throw new SwarmException('Native input envelope contains invalid attachment invocation options.');
                }
                $profiles = [];
                foreach ($attachment['swarm_invocation_options'] as $provider => $profile) {
                    if (! is_string($provider) || $provider === '' || ! is_array($profile)
                        || ! is_array($profile['headers'] ?? null)
                        || ! is_array($profile['provider_options'] ?? null)
                        || array_filter($profile['headers'], static fn (mixed $value, mixed $key): bool => ! is_string($key) || ! is_string($value), ARRAY_FILTER_USE_BOTH) !== []) {
                        throw new SwarmException('Native input envelope contains invalid attachment invocation options.');
                    }

                    $profiles[$provider] = [
                        'headers' => $profile['headers'],
                        'provider_options' => PlainData::array($profile['provider_options'], 'native attachment provider options'),
                    ];
                }
                $invocationOptions[$index] = $profiles;
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
            attachmentInvocationOptions: $invocationOptions,
            consumedMessageConfigurationIds: self::consumedIds($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected static function consumedIds(array $payload): array
    {
        $ids = $payload['consumed_message_configuration_ids'] ?? [];
        if (! is_array($ids) || ! array_is_list($ids)
            || array_filter($ids, static fn (mixed $id): bool => ! is_string($id) || $id === '') !== []) {
            throw new SwarmException('Native input envelope contains invalid consumed message configuration IDs.');
        }

        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = $id;
        }

        return array_values(array_unique($normalized));
    }

    public function captureRecoverableInvocationOptions(): void
    {
        foreach ($this->attachments as $index => $attachment) {
            $headers = self::rawSetting($attachment, 'headers');
            $providerOptions = self::rawSetting($attachment, 'providerOptions');
            $dynamic = $headers instanceof SerializableClosure || $providerOptions instanceof SerializableClosure;

            $providers = [];
            foreach ($this->recipients as $recipient) {
                if ($recipient->attachments !== null && ! in_array($index, $recipient->attachments, true)) {
                    continue;
                }

                if (! $dynamic) {
                    $providers['*'] = 'static';

                    continue;
                }

                if (! ($recipient->provider instanceof Lab) && ! is_string($recipient->provider)) {
                    throw new SwarmException("Recoverable native attachment [{$index}] uses provider-dependent headers or options, so every recipient must declare one provider explicitly.");
                }

                $providers[self::providerKey($recipient->provider)] = $recipient->provider;
            }

            foreach ($providers as $key => $provider) {
                $resolvedHeaders = $dynamic
                    ? $attachment->headers($provider)
                    : $headers;
                $resolvedOptions = $dynamic
                    ? $attachment->providerOptions($provider)
                    : $providerOptions;

                $this->attachmentInvocationOptions[$index][$key] = [
                    'headers' => PlainData::array($resolvedHeaders, 'native attachment headers'),
                    'provider_options' => PlainData::array($resolvedOptions, 'native attachment provider options'),
                ];
            }
        }
    }

    public static function assertMessageAttachmentIsReconstructible(File $attachment): void
    {
        $headers = self::rawSetting($attachment, 'headers');
        $providerOptions = self::rawSetting($attachment, 'providerOptions');

        if ($headers instanceof SerializableClosure || $providerOptions instanceof SerializableClosure
            || $headers !== [] || $providerOptions !== []) {
            throw new SwarmException('Recoverable withMessages attachments cannot carry headers or provider options. Use top-level native input attachments for explicitly frozen invocation profiles, or remove those options before dispatch.');
        }
    }

    protected function applyInvocationOptions(File $attachment, int|false $index, NativeInputRecipient $selection): File
    {
        if (! is_int($index)) {
            return $attachment;
        }

        $profiles = $this->attachmentInvocationOptions[$index] ?? [];
        $profile = $profiles['*'] ?? null;
        if ($selection->provider instanceof Lab || is_string($selection->provider)) {
            $profile = $profiles[self::providerKey($selection->provider)] ?? $profile;
        }

        if (is_array($profile)) {
            $attachment->withHeaders($profile['headers'] ?? []);
            $attachment->withProviderOptions($profile['provider_options'] ?? []);
        }

        return $attachment;
    }

    /** @return array<string, mixed>|SerializableClosure */
    protected static function rawSetting(File $attachment, string $property): array|SerializableClosure
    {
        $reflection = new ReflectionProperty(File::class, $property);

        /** @var array<string, mixed>|SerializableClosure $value */
        $value = $reflection->getValue($attachment);

        return $value;
    }

    protected static function providerKey(Lab|string $provider): string
    {
        return $provider instanceof Lab ? $provider->value : $provider;
    }
}
