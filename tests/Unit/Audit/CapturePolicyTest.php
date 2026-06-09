<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Config\Repository as ConfigRepository;

function policyConfig(array $captureFlags = []): ConfigRepository
{
    return new ConfigRepository([
        'swarm' => [
            'capture' => array_merge([
                'inputs' => false,
                'outputs' => false,
                'artifacts' => false,
                'active_context' => false,
            ], $captureFlags),
        ],
    ]);
}

test('container default binding resolves to BooleanCapturePolicy', function (): void {
    expect(app(CapturePolicy::class))->toBeInstanceOf(BooleanCapturePolicy::class);
});

test('BooleanCapturePolicy returns Redact for every category when all booleans are false', function (): void {
    $policy = new BooleanCapturePolicy(policyConfig());

    expect($policy->inputs())->toBe(CaptureDecision::Redact);
    expect($policy->outputs())->toBe(CaptureDecision::Redact);
    expect($policy->artifacts())->toBe(CaptureDecision::Redact);
    expect($policy->activeContext())->toBe(CaptureDecision::Redact);
});

test('BooleanCapturePolicy returns Full when the matching boolean is true', function (): void {
    $policy = new BooleanCapturePolicy(policyConfig(['inputs' => true]));

    expect($policy->inputs())->toBe(CaptureDecision::Full);
});

test('BooleanCapturePolicy artifacts requires outputs as well', function (): void {
    $artifactsOnly = new BooleanCapturePolicy(policyConfig(['artifacts' => true]));
    expect($artifactsOnly->artifacts())->toBe(CaptureDecision::Redact);

    $outputsOnly = new BooleanCapturePolicy(policyConfig(['outputs' => true]));
    expect($outputsOnly->artifacts())->toBe(CaptureDecision::Redact);

    $both = new BooleanCapturePolicy(policyConfig(['outputs' => true, 'artifacts' => true]));
    expect($both->artifacts())->toBe(CaptureDecision::Full);
});

test('BooleanCapturePolicy ignores context and actor parameters', function (): void {
    $policy = new BooleanCapturePolicy(policyConfig(['inputs' => true]));

    $context = RunContext::fromTask('task')->withActor(Actor::system('cron'));
    $actor = $context->actor();

    expect($policy->inputs($context, $actor))->toBe(CaptureDecision::Full);
    expect($policy->inputs(null, null))->toBe(CaptureDecision::Full);
});

test('SwarmCapture delegates capturesInputs to the policy decision', function (): void {
    config(['swarm.capture.inputs' => true]);
    expect(app(SwarmCapture::class)->capturesInputs())->toBeTrue();

    config(['swarm.capture.inputs' => false]);
    expect(app(SwarmCapture::class)->capturesInputs())->toBeFalse();
});

test('SwarmCapture respects a rebound custom policy', function (): void {
    $customPolicy = new class implements CapturePolicy
    {
        public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Skip;
        }

        public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Redact;
        }

        public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }
    };

    app()->instance(CapturePolicy::class, $customPolicy);
    app()->forgetInstance(SwarmCapture::class);

    $capture = app(SwarmCapture::class);

    expect($capture->capturesInputs())->toBeTrue();
    expect($capture->capturesOutputs())->toBeFalse();  // Skip is treated as "not Full"
    expect($capture->capturesArtifacts())->toBeFalse(); // Redact is "not Full"
    expect($capture->capturesActiveContext())->toBeTrue();
});

test('SwarmCapture::input returns the value verbatim when policy returns Full', function (): void {
    config(['swarm.capture.inputs' => true]);

    expect(app(SwarmCapture::class)->input('sensitive prompt'))->toBe('sensitive prompt');
});

test('SwarmCapture::input redacts when policy returns Redact', function (): void {
    config(['swarm.capture.inputs' => false]);

    expect(app(SwarmCapture::class)->input('sensitive prompt'))->toBe(SwarmCapture::REDACTED);
});

test('applyInput/applyOutput return null for Skip, REDACTED for Redact, value for Full', function (): void {
    $policy = new class implements CapturePolicy
    {
        public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Skip;
        }

        public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Redact;
        }

        public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }
    };

    $capture = new SwarmCapture(policyConfig(), $policy);

    expect($capture->applyInput('prompt'))->toBeNull();          // Skip → null
    expect($capture->applyOutput('answer'))->toBe(SwarmCapture::REDACTED); // Redact
});

test('applyOutput returns the value verbatim when outputs are Full', function (): void {
    $policy = new class implements CapturePolicy
    {
        public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }
    };

    $capture = new SwarmCapture(policyConfig(), $policy);

    expect($capture->applyOutput('answer'))->toBe('answer');
});

test('stepToPersistedArray omits input/output keys under Skip', function (): void {
    $policy = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Skip,
        artifacts: CaptureDecision::Skip,
        activeContext: CaptureDecision::Skip,
    );

    $capture = new SwarmCapture(policyConfig(), $policy);

    $step = new SwarmStep(
        agentClass: 'App\\Ai\\Agents\\Writer',
        input: 'sensitive prompt',
        output: 'sensitive answer',
        artifacts: [],
        metadata: ['index' => 0],
    );

    $array = $capture->stepToPersistedArray($step);

    expect($array)->not->toHaveKey('input');
    expect($array)->not->toHaveKey('output');
    expect($array['agent_class'])->toBe('App\\Ai\\Agents\\Writer');
});

test('stepToPersistedArray redacts (keeps keys) under Redact and keeps values under Full', function (): void {
    $redactPolicy = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Redact,
        outputs: CaptureDecision::Redact,
        artifacts: CaptureDecision::Redact,
        activeContext: CaptureDecision::Redact,
    );

    $step = new SwarmStep(
        agentClass: 'App\\Ai\\Agents\\Writer',
        input: 'sensitive prompt',
        output: 'sensitive answer',
        artifacts: [],
        metadata: ['index' => 0],
    );

    $redacted = (new SwarmCapture(policyConfig(), $redactPolicy))->stepToPersistedArray($step);
    expect($redacted['input'])->toBe(SwarmCapture::REDACTED);
    expect($redacted['output'])->toBe(SwarmCapture::REDACTED);

    $fullPolicy = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Full,
        outputs: CaptureDecision::Full,
        artifacts: CaptureDecision::Full,
        activeContext: CaptureDecision::Full,
    );

    $full = (new SwarmCapture(policyConfig(), $fullPolicy))->stepToPersistedArray($step);
    expect($full['input'])->toBe('sensitive prompt');
    expect($full['output'])->toBe('sensitive answer');
});

test('omitSkippedHistoryContextKeys removes input and output-derived keys under Skip', function (): void {
    $policy = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Skip,
    );

    $capture = new SwarmCapture(policyConfig(), $policy);

    $contextArray = [
        'run_id' => 'run-1',
        'input' => 'prompt',
        'data' => [
            'input' => 'prompt',
            'last_output' => 'answer',
            'steps' => 1,
        ],
        'metadata' => [],
        'artifacts' => [],
    ];

    $result = $capture->omitSkippedHistoryContextKeys($contextArray);

    expect($result)->not->toHaveKey('input');
    expect($result['data'])->not->toHaveKey('input');
    expect($result['data'])->not->toHaveKey('last_output');
    expect($result['data']['steps'])->toBe(1);
});

test('failureArray omits message under Skip but keeps class and extras', function (): void {
    $skip = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Skip,
    );

    $payload = (new SwarmCapture(policyConfig(), $skip))->failureArray(
        new RuntimeException('boom'),
        ['timed_out' => true],
    );

    expect($payload)->not->toHaveKey('message');
    expect($payload['class'])->toBe(RuntimeException::class);
    expect($payload['timed_out'])->toBeTrue();

    $redact = new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Redact,
        outputs: CaptureDecision::Redact,
    );

    $redacted = (new SwarmCapture(policyConfig(), $redact))->failureArray(new RuntimeException('boom'));
    expect($redacted['message'])->toBe(SwarmCapture::REDACTED);
    expect($redacted['class'])->toBe(RuntimeException::class);
});
