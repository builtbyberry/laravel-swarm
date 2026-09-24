<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class ReconstructedHistoryAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public const DIVERGENCES = [
        'approval-validation',
        'provider-selection',
        'approval-events',
        'conversation-remembering',
        'model-failover',
    ];

    /** @param iterable<int, Message> $history */
    public function __construct(private readonly iterable $history) {}

    public function instructions(): string
    {
        return 'Continue from reconstructed public conversation history.';
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return [new NativeApprovalTool];
    }
}
