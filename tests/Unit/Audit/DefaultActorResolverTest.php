<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\DefaultActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Context;

class ResolverAuthFixture implements Authenticatable
{
    public function __construct(private mixed $id = 'u-42') {}

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

function authFactoryReturning(?Authenticatable $user): AuthFactory
{
    return new class($user) implements AuthFactory
    {
        public function __construct(private ?Authenticatable $user) {}

        public function guard($name = null): Guard
        {
            return new class($this->user) implements Guard
            {
                public function __construct(private ?Authenticatable $user) {}

                public function check(): bool
                {
                    return $this->user !== null;
                }

                public function guest(): bool
                {
                    return $this->user === null;
                }

                public function user(): ?Authenticatable
                {
                    return $this->user;
                }

                public function id(): mixed
                {
                    return $this->user?->getAuthIdentifier();
                }

                public function validate(array $credentials = []): bool
                {
                    return false;
                }

                public function hasUser(): bool
                {
                    return $this->user !== null;
                }

                public function setUser(Authenticatable $user): void
                {
                    $this->user = $user;
                }
            };
        }

        public function shouldUse($name): void {}
    };
}

beforeEach(function (): void {
    Context::flush();
});

test('container default binding resolves to DefaultActorResolver', function (): void {
    expect(app(ActorResolver::class))->toBeInstanceOf(DefaultActorResolver::class);
});

test('returns null when neither context nor auth provide an actor', function (): void {
    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    expect($resolver->resolve())->toBeNull();
});

test('returns an Actor instance from Context unchanged', function (): void {
    $actor = Actor::system('cron:nightly');
    Context::add('swarm:actor', $actor);

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    expect($resolver->resolve())->toBe($actor);
});

test('returns a user actor for an Authenticatable bound in Context', function (): void {
    Context::add('swarm:actor', new ResolverAuthFixture('u-99'));

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    $resolved = $resolver->resolve();

    expect($resolved)->not->toBeNull();
    expect($resolved->type)->toBe('user');
    expect($resolved->id)->toBe('u-99');
});

test('parses a "type:id" string bound in Context', function (): void {
    Context::add('swarm:actor', 'api_token:abc-123');

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    $resolved = $resolver->resolve();

    expect($resolved)->not->toBeNull();
    expect($resolved->type)->toBe('api_token');
    expect($resolved->id)->toBe('abc-123');
});

test('rehydrates an Actor array bound in Context', function (): void {
    Context::add('swarm:actor', [
        'id' => 'svc-7',
        'type' => 'service',
        'name' => 'Billing Service',
    ]);

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    $resolved = $resolver->resolve();

    expect($resolved)->not->toBeNull();
    expect($resolved->type)->toBe('service');
    expect($resolved->id)->toBe('svc-7');
    expect($resolved->name)->toBe('Billing Service');
});

test('falls back to authenticated user when Context is empty', function (): void {
    $resolver = new DefaultActorResolver(authFactoryReturning(new ResolverAuthFixture('u-10')));

    $resolved = $resolver->resolve();

    expect($resolved)->not->toBeNull();
    expect($resolved->type)->toBe('user');
    expect($resolved->id)->toBe('u-10');
});

test('Context takes precedence over auth fallback', function (): void {
    Context::add('swarm:actor', Actor::system('cron:nightly'));

    $resolver = new DefaultActorResolver(authFactoryReturning(new ResolverAuthFixture('u-10')));

    $resolved = $resolver->resolve();

    expect($resolved)->not->toBeNull();
    expect($resolved->type)->toBe('system');
    expect($resolved->id)->toBe('cron:nightly');
});

test('ignores empty-string Context values', function (): void {
    Context::add('swarm:actor', '');

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    expect($resolver->resolve())->toBeNull();
});

test('ignores malformed array Context values', function (): void {
    Context::add('swarm:actor', ['no-id-or-type' => true]);

    $resolver = new DefaultActorResolver(authFactoryReturning(null));

    expect($resolver->resolve())->toBeNull();
});
