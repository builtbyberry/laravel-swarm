<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use Generator;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

class PendingAgent extends PlainStreamEditor
{
    public static int $calls = 0;

    public static int $afterApproval = 0;

    public static function pending(): AgentResponse
    {
        return AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('approval-secret', 'private-tool', ['secret' => 'argument-secret'], 'reason-secret'),
        ]);
    }

    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        self::$calls++;
        if (config('tests.native.throw', false)) {
            throw ApprovalNotResumableException::make();
        }

        return self::pending();
    }

    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return (new StreamableAgentResponse('pending-invocation', function (): Generator {
            self::$calls++;
            if (config('tests.native.throw', false)) {
                throw ApprovalNotResumableException::make();
            }
            if (! config('tests.native.final_only', false)) {
                yield new ToolApprovalRequest('private-event', self::pending()->pendingApprovals, 123, [['secret' => 'provider-secret']]);
                self::$afterApproval++;
            }
            yield new StreamEnd('end', 'stop', new Usage, 123);
        }, new Meta('fake', 'test')))->then(function (StreamedAgentResponse $response): void {
            $response->withPendingApprovals(self::pending()->pendingApprovals);
        });
    }
}
