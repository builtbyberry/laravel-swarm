<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support;

use Illuminate\Http\Request;

class WebhookAuthCallbacks
{
    public function __invoke(Request $request): bool
    {
        return $request->headers->get('X-Test-Webhook-Auth') === 'allow';
    }

    public function authorize(Request $request): bool
    {
        return $request->headers->get('X-Test-Webhook-Method') === 'allow';
    }

    public function deny(Request $request): bool
    {
        return false;
    }
}
