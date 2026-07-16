<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\AnyOfRoutePlanCoordinator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Schema\SchemaNormalizer;

/**
 * Serialize a coordinator's schema() the way laravel/ai does before dispatch:
 * wrap the property map in an object type, render to a raw JSON Schema array,
 * then run it through laravel/ai's SchemaNormalizer.
 *
 * @param  array<string, Type>  $properties
 * @return array<string, mixed>
 */
function normalizeCoordinatorSchema(JsonSchemaTypeFactory $factory, array $properties): array
{
    return SchemaNormalizer::normalize($factory->object($properties)->toArray());
}

test('the coordinator route-plan schema round-trips through laravel/ai with anyOf preserved', function () {
    $factory = new JsonSchemaTypeFactory;

    $normalized = normalizeCoordinatorSchema(
        $factory,
        (new AnyOfRoutePlanCoordinator)->schema($factory),
    );

    $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);

    // supportsAnyOf() is true on ^0.9, so the normalizer preserves both unions
    // (the node union on `respond`, the finish union on `done`) rather than
    // collapsing them.
    expect(substr_count($encoded, '"anyOf"'))->toBe(2);

    $respond = $normalized['properties']['nodes']['properties']['respond'];
    expect($respond)->toHaveKey('anyOf');
    // The node union enumerates every node variant the planner accepts.
    expect($encoded)->toContain('worker', 'rollup', 'parallel', 'finish');
});

test('the finish union expresses exactly-one-of output / output_from', function () {
    $factory = new JsonSchemaTypeFactory;

    $normalized = normalizeCoordinatorSchema(
        $factory,
        (new AnyOfRoutePlanCoordinator)->schema($factory),
    );

    $done = $normalized['properties']['nodes']['properties']['done'];

    expect($done)->toHaveKey('anyOf');
    expect($done['anyOf'])->toHaveCount(2);

    // Each branch requires the discriminator plus exactly one of the two output
    // keys — the schema-level encoding of the planner's exactly-one-of rule.
    $requiredSets = array_map(
        fn (array $branch): array => $branch['required'],
        $done['anyOf'],
    );

    expect($requiredSets)->toContain(['type', 'output']);
    expect($requiredSets)->toContain(['type', 'output_from']);
});

test('RoutePlanSchema node union is composable directly by coordinator authors', function () {
    $factory = new JsonSchemaTypeFactory;

    // The helper returns a first-class Type usable anywhere a schema property is
    // expected — not only inside the shipped example.
    $node = RoutePlanSchema::node($factory);
    $raw = $node->toArray();

    expect($raw)->toHaveKey('anyOf');
    expect($raw['anyOf'])->toHaveCount(5); // worker, rollup, parallel, finish×2

    $normalized = SchemaNormalizer::normalize($raw);
    expect($normalized)->toHaveKey('anyOf');
});
