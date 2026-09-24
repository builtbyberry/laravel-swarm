<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
class NativeApprovalAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /** @var list<array{tool: string, id: ?string, value: mixed}> */
    public static array $effects = [];

    public function instructions(): string
    {
        return 'Exercise Laravel AI native tool approval contracts.';
    }

    public function tools(): iterable
    {
        return [
            new NativeOrdinaryTool,
            new NativeApprovalTool,
            new NativeEditableApprovalTool,
            new NativeRejectableApprovalTool,
        ];
    }
}
