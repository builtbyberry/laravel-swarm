<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->namespace = StarterExampleRenderer::render('hierarchical-support-triage');
});

test('hierarchical-support-triage coordinator routes each request to the matching handler', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\HierarchicalSupportTriage\\SupportTriageRouter';

    expect(class_exists($swarmClass))->toBeTrue();

    $billing = $swarmClass::make()->prompt('I need a refund for a double charge on my invoice.');
    $technical = $swarmClass::make()->prompt('The app crashes with a login error every time.');

    expect($billing)->toBeInstanceOf(SwarmResponse::class)
        ->and($technical)->toBeInstanceOf(SwarmResponse::class);

    // Step 0 is always the coordinator; step 1 is the routed handler.
    $billingHandler = $billing->steps[1]->agentClass;
    $technicalHandler = $technical->steps[1]->agentClass;

    expect($billingHandler)->toEndWith('\\BillingResponder')
        ->and($technicalHandler)->toEndWith('\\TechnicalResponder')
        // Routing landed on two DISTINCT handlers — the whole point of hierarchical.
        ->and($billingHandler)->not->toBe($technicalHandler);

    // The finish node returns the routed handler's output as the swarm result.
    expect((string) $billing)->toContain('[BillingResponder]')
        ->and((string) $technical)->toContain('[TechnicalResponder]')
        // The un-routed handler never ran, so its marker is absent.
        ->and((string) $billing)->not->toContain('[TechnicalResponder]');
});

test('hierarchical-support-triage falls back to the general handler', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\HierarchicalSupportTriage\\SupportTriageRouter';

    $response = $swarmClass::make()->prompt('What are your support hours?');

    expect($response->steps[1]->agentClass)->toEndWith('\\GeneralResponder')
        ->and((string) $response)->toContain('[GeneralResponder]');
});

test('hierarchical-support-triage runner command reports the routed handler', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleSupportTriageCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:support-triage', ['request' => 'Please refund my last payment.']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('BillingResponder')
        ->toContain('[BillingResponder]');
});
