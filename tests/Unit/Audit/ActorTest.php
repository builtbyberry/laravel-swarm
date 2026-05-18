<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Auth\Authenticatable;

class ActorAuthFixture implements Authenticatable
{
    public function __construct(private mixed $id = 'user-42') {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}

test('constructor stores id, type, name, and metadata', function (): void {
    $actor = new Actor(id: 'u-1', type: 'user', name: 'Ada', metadata: ['tenant' => 'acme']);

    expect($actor->id)->toBe('u-1');
    expect($actor->type)->toBe('user');
    expect($actor->name)->toBe('Ada');
    expect($actor->metadata)->toBe(['tenant' => 'acme']);
});

test('constructor rejects empty id', function (): void {
    expect(fn () => new Actor(id: '', type: 'user'))
        ->toThrow(SwarmException::class, 'Actor id must be a non-empty string.');
});

test('constructor rejects empty type', function (): void {
    expect(fn () => new Actor(id: 'u-1', type: ''))
        ->toThrow(SwarmException::class, 'Actor type must be a non-empty string.');
});

test('system named constructor produces a system actor', function (): void {
    $actor = Actor::system();

    expect($actor->id)->toBe('system');
    expect($actor->type)->toBe('system');
    expect($actor->name)->toBeNull();
});

test('system named constructor accepts custom id and name', function (): void {
    $actor = Actor::system('cron:nightly', 'Nightly Cron');

    expect($actor->id)->toBe('cron:nightly');
    expect($actor->type)->toBe('system');
    expect($actor->name)->toBe('Nightly Cron');
});

test('user named constructor extracts auth identifier', function (): void {
    $actor = Actor::user(new ActorAuthFixture('u-99'));

    expect($actor->id)->toBe('u-99');
    expect($actor->type)->toBe('user');
});

test('user named constructor casts non-string auth identifiers', function (): void {
    $actor = Actor::user(new ActorAuthFixture(42));

    expect($actor->id)->toBe('42');
    expect($actor->type)->toBe('user');
});

test('fromAny returns an Actor instance unchanged', function (): void {
    $actor = Actor::system('foo');

    expect(Actor::fromAny($actor))->toBe($actor);
});

test('fromAny converts an Authenticatable to a user actor', function (): void {
    $actor = Actor::fromAny(new ActorAuthFixture('u-7'));

    expect($actor->id)->toBe('u-7');
    expect($actor->type)->toBe('user');
});

test('fromAny treats "type:id" strings as typed actors', function (): void {
    $actor = Actor::fromAny('api_token:abc-123');

    expect($actor->type)->toBe('api_token');
    expect($actor->id)->toBe('abc-123');
});

test('fromAny treats bare strings as system actors', function (): void {
    $actor = Actor::fromAny('billing-cron');

    expect($actor->type)->toBe('system');
    expect($actor->id)->toBe('billing-cron');
});

test('fromArray rehydrates an Actor from its toArray output', function (): void {
    $original = new Actor(id: 'u-1', type: 'user', name: 'Ada', metadata: ['tenant' => 'acme']);

    $hydrated = Actor::fromArray($original->toArray());

    expect($hydrated->id)->toBe('u-1');
    expect($hydrated->type)->toBe('user');
    expect($hydrated->name)->toBe('Ada');
    expect($hydrated->metadata)->toBe(['tenant' => 'acme']);
});

test('fromArray rejects payloads missing id', function (): void {
    expect(fn () => Actor::fromArray(['type' => 'user']))
        ->toThrow(SwarmException::class, 'Actor array must contain a string [id].');
});

test('fromArray rejects payloads missing type', function (): void {
    expect(fn () => Actor::fromArray(['id' => 'u-1']))
        ->toThrow(SwarmException::class, 'Actor array must contain a string [type].');
});

test('fromArray rejects non-string name', function (): void {
    expect(fn () => Actor::fromArray(['id' => 'u-1', 'type' => 'user', 'name' => 42]))
        ->toThrow(SwarmException::class, 'Actor [name] must be a string or null.');
});

test('fromArray rejects non-array metadata', function (): void {
    expect(fn () => Actor::fromArray(['id' => 'u-1', 'type' => 'user', 'metadata' => 'not-an-array']))
        ->toThrow(SwarmException::class, 'Actor [metadata] must be an array.');
});

test('toArray emits id, type, name, and metadata', function (): void {
    $actor = new Actor(id: 'u-1', type: 'user', name: 'Ada', metadata: ['tenant' => 'acme']);

    expect($actor->toArray())->toBe([
        'id' => 'u-1',
        'type' => 'user',
        'name' => 'Ada',
        'metadata' => ['tenant' => 'acme'],
    ]);
});
