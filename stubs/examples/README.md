# Laravel Swarm Starter Examples

This directory is the canonical, runnable starter pack that
`php artisan swarm:install:examples` copies into a fresh Laravel app. Each
example ships as a complete `app/` tree (swarm, agents, runner command) plus
its own README.

Every starter is built on `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent`
so it runs end-to-end with **no provider configured and no API key**. The
agents return canned text. Each agent file has a `TODO` marker pointing to
the one-line edit that swaps in a real Laravel AI agent.

## The three starters

1. **`sequential-blog-pipeline/`** — three agents in order: outline → draft
   → polish. The hello world. Plain in-memory `prompt()`. No queue, no DB.
2. **`parallel-research-fanout/`** — three scouts run concurrently on the
   same prompt; results merge into a single response. Demonstrates the
   parallel topology and the container-resolvable agent contract.
3. **`durable-approval-workflow/`** — durable two-step swarm with a
   `policy_decision` wait between the steps. Resumed by a signal. The
   showcase example for the human-in-the-loop pattern.

## Reference examples

The package also ships a much larger collection of reference-only,
read-the-README examples under the top-level `examples/` directory. Those
are not auto-installed; they cover advanced surfaces (hierarchical routing,
streaming, broadcasts, Pulse, guardrails, webhooks). When a starter here
graduates and you need the next pattern, head there.

## Placeholders

Every PHP file uses `{{ rootNamespace }}` (default `App`) where the
destination namespace goes. The installer rewrites this at copy time. Hand
edits to the stubs should keep the placeholder shape so the installer keeps
working.
