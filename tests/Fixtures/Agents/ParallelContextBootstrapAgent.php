<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use Illuminate\Container\Container;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use LogicException;

final class ParallelContextBootstrapAgent extends SerializationBoundaryAgent
{
    public function __construct()
    {
        parent::__construct();

        if (getenv('LARAVEL_INVOKABLE_CLOSURE') === false) {
            return;
        }

        if (ActiveRunContext::current() === null) {
            throw new LogicException('Parallel child resolved its agent before entering the active run context.');
        }

        $container = Container::getInstance();
        foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class] as $contract) {
            $container->bind($contract, static fn () => throw new LogicException("Child must not resolve parent persistence contract [{$contract}]."));
        }
    }

    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse('context-bootstrap-prompt', 'context-bootstrap', new TextUsage(1, 1), new Meta('fixture', 'parallel-live'));
    }

    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('context-bootstrap-stream', function (): \Generator {
            yield new TextDelta('context-bootstrap-delta', 'context-bootstrap-message', 'context-bootstrap', 1710000001);
            yield new StreamEnd('context-bootstrap-end', 'stop', new TextUsage(1, 1), 1710000002);
        }, new Meta('fixture', 'parallel-live'));
    }
}
