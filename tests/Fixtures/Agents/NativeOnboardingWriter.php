<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeOnboardingLookup;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;

/** Generated-style native Laravel AI agent used by the onboarding proof. */
final class NativeOnboardingWriter implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Use the release lookup tool, then write one concise release note.';
    }

    /** @return Message[] */
    public function messages(): iterable
    {
        return [];
    }

    /** @return list<Agent|Tool|ProviderTool> */
    public function tools(): iterable
    {
        return [new NativeOnboardingLookup];
    }
}
