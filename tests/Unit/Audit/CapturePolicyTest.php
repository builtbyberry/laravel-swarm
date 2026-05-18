<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
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
