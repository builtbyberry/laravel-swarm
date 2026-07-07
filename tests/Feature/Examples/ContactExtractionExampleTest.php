<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Contracts\HasStructuredOutput;

beforeEach(function () {
    $this->namespace = StarterExampleRenderer::render('sequential-contact-extraction');
});

test('sequential-contact-extraction starter produces a validated structured record end-to-end', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\SequentialContactExtraction\\ContactExtractor';

    expect(class_exists($swarmClass))->toBeTrue();

    $response = $swarmClass::make()->prompt(
        'Hi, this is Ada Lovelace — reach me at ADA@Analytical-Engines.Example or 555-0100.'
    );

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and($response->steps)->toHaveCount(2);

    // The final output is a structured, validated record — not free text.
    $record = json_decode($response->output, true, flags: JSON_THROW_ON_ERROR);

    expect($record)->toBeArray()
        ->toHaveKeys(['name', 'email', 'phone', 'company', 'valid', 'missing'])
        ->and($record['valid'])->toBeTrue()
        ->and($record['missing'])->toBe([])
        // Normaliser canonicalised the fields the extractor produced.
        ->and($record['email'])->toBe('ada@analytical-engines.example')
        ->and($record['phone'])->toBe('5550100')
        ->and($record['name'])->toBe('Ada Lovelace');

    // Sequential contract: the normaliser's input is the extractor's structured output.
    expect($response->steps[1]->input)->toBe($response->steps[0]->output);

    // Step 1's output is itself valid structured JSON (the extracted fields).
    $extracted = json_decode($response->steps[0]->output, true, flags: JSON_THROW_ON_ERROR);
    expect($extracted)->toBeArray()->toHaveKeys(['name', 'email', 'phone', 'company']);
});

test('both extraction agents declare a Laravel AI structured-output schema', function () {
    // This is what makes the example STRUCTURED output rather than free text:
    // each agent implements HasStructuredOutput and constrains a real provider.
    foreach (['FieldExtractor', 'RecordNormalizer'] as $agent) {
        $class = $this->namespace.'\\Ai\\Agents\\SequentialContactExtraction\\'.$agent;

        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, HasStructuredOutput::class))->toBeTrue("[{$agent}] must declare structured output");
    }
});

test('sequential-contact-extraction runner command reports a valid record', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleContactExtractionCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:contact-extraction', [
        'blurb' => 'Reach Grace Hopper at grace@navy.example, phone 555 0199.',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('Valid record')
        ->toContain('"valid": true')
        ->toContain('grace@navy.example');
});
