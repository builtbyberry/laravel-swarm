<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

/**
 * A vendor-only twin of {@see RememberingWriter}.
 *
 * Exists for one branch: `RemembersRunContext::messages()` decides what to pass
 * the propagation policy with `$this instanceof Agent ? $this : null`. Before
 * v0.23.0 that checked the swarm marker, so an agent implementing only the
 * vendor contract passed **null** — no exception, no TypeError, just per-agent
 * memory filtering quietly switching itself off. It is the only failure mode in
 * this area that is silent rather than loud, which makes it the one most worth
 * asserting.
 *
 * {@see RememberingWriter} implements the swarm marker, so it can never
 * exercise this branch — hence the twin. Deliberately implements ONLY the
 * vendor contract; do not add the swarm marker.
 */
class VendorOnlyRememberingWriter implements Agent, Conversational
{
    use Promptable;
    use RemembersRunContext;

    public function instructions(): string
    {
        return 'You are a writer who remembers the run.';
    }
}
