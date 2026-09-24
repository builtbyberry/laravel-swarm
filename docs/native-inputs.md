# Native messages and attachments

Laravel Swarm can pass Laravel AI `UserMessage` input—and an `AgentInput` whose
current value is a user message—through a workflow without
inventing a second message grammar. This v0.28 surface is default-off while a
mixed worker fleet is being upgraded:

```env
SWARM_NATIVE_INPUTS_ENABLED=true
```

```php
use App\Ai\Swarms\DocumentReview;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Messages\UserMessage;

$response = DocumentReview::make()->prompt(new UserMessage(
    'Review this contract.',
    [new StoredDocument('contracts/acme.pdf', 'private')],
));
```

Strings, structured arrays and existing `RunContext` payloads keep their existing
wire shape and routing. A text-only `UserMessage` follows those same topology
rules. Swarm checks `AgentInput::decisions()` before reading its message and fails
with continuation guidance when decisions are present; native approval continuation
is a separate component and is not introduced by this input surface.

## Execution matrix

Native input does not make a previously unsupported execution combination
available. It follows the existing v0.27.0 execution envelope (tag commit
`06ee4c8c095f3999f32aebd849c3534f5283d1a4`):

| Topology | `prompt()` | `queue()` | `stream()` / in-process broadcast | queued broadcast | durable |
|---|---:|---:|---:|---:|---:|
| Sequential | yes | yes | yes | yes | yes |
| Parallel | yes | yes | no live ordered stream | no | yes |
| Generated hierarchy | yes | yes | yes | yes | yes |
| Static hierarchy | yes | yes | yes | yes | yes |

Inline `Swarm::agent()`, `sequential()`, `parallel()` and `hierarchical()` builders
retain their in-process-only boundary. Background and durable work still requires
a named, container-resolvable swarm class.

## Explicit recipients

Direct input defaults only to sequential slot 0 or a generated coordinator.
Attachments never follow predecessor output automatically. Parallel workers,
static nodes, later sequential slots and generated workers must be selected by
stable slot/node identity:

```php
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::fromTask('Review the contract')
    ->withAgentInput(
        new UserMessage('Review the contract', [$contract]),
        [
            NativeInputRecipient::sequential(0, textSource: 'original'),
            NativeInputRecipient::sequential(1, attachments: [0]),
        ],
    );
```

Attachment selection and text selection are independent. The default
`textSource: 'topology'` preserves predecessor/composed text. Select `original`
only when that recipient should receive the original message text. Generated
recipients are checked after the coordinator DAG is validated, so model output
cannot grant itself file access. Repeated agent classes are addressed by slot or
node, never by class name.

Per-recipient provider, model and provider timeout overrides are optional:

```php
NativeInputRecipient::parallel(0, attachments: [0])
    ->withInvocation(provider: 'openai', model: 'gpt-5-mini', timeout: 45);
```

Absent values are not synthesized; the agent/provider declarations remain in
control. The timeout is a provider-call timeout, not a hard workflow cancel.

## Operational storage and ownership

Request-local sequential/coordinator input can retain Laravel AI file objects in
memory. Queue, durable, static, parallel and other cross-process paths use a
versioned operational envelope in `swarm_native_inputs`. Queue payloads contain
only an opaque reference. The message, attachment locators, recipient bindings
and invocation options are sealed with the package persistence cipher; this is
independent of capture, so capture-off recovery never consumes `[redacted]`.
Plain attachment headers and provider options are reconstructed. Provider-dependent
closures require every receiving route to name one scalar provider so Swarm can
seal the resolved plain values; ambiguous or non-plain values fail before dispatch.

Recoverable input requires:

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_ENCRYPT_AT_REST=true
SWARM_NATIVE_INPUTS_DISK=private
```

The named disk is application-owned and must be private. Local and base64
image/document/audio/video attachments are copied there before dispatch. Existing
stored files must already use that disk. Provider file references remain bound to
the provider account selected by the application. Both are denied by default:
bind `AuthorizesNativeInputAttachment` to an application policy that verifies the
file belongs to the run's actor and tenant before opting them in. Swarm-created
promoted files are already run-scoped and do not call that policy.

```php
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;

$this->app->bind(AuthorizesNativeInputAttachment::class, App\Ai\NativeFilePolicy::class);
```

The policy is re-evaluated in every worker before the attachment is released to
an agent. Remote URLs are request-local; recoverable dispatch refuses them rather
than adding an unbounded server-side fetch. Provider support for each modality
still controls what the final native request may contain.

Each envelope binds its run, actor/tenant projection, recipient, expiry and
content hash. Missing, revoked, expired, wrong-run, wrong-actor/tenant, unknown
version and mutated-file reads fail before the agent call. `swarm:prune` removes
expired envelopes and only the temporary files Swarm itself promoted; it never
deletes application-owned stored files.

`SWARM_NATIVE_INPUTS_RETENTION_SECONDS` is an execution deadline as well as a
retention setting. Size it beyond the longest queue delay plus the longest
durable workflow/recovery window. Schedule `swarm:prune`; rows whose owned-file
cleanup fails are retained for a later retry instead of losing the retry locator.
`swarm:health` reports whether native readers are ready and, while admission is
disabled, whether active envelopes still need to drain.

## Deployment and rollback

1. Deploy the additive migration and v1 readers to every worker.
2. Configure the protected database store/private disk and leave the feature off.
3. Restart workers, then enable `SWARM_NATIVE_INPUTS_ENABLED=true`.
4. Before rollback, disable new admission. Existing opaque references remain
   readable while the flag is off; confirm `swarm:health` reports zero active
   envelopes before removing readers or rolling back the migration.

Recoverable native admission must begin outside an open database transaction.
Swarm stages the sealed cleanup locator before promoting file bytes, then activates
it only after every write succeeds; allowing an outer rollback could erase that
locator after the filesystem write. Native marker jobs still default to Laravel's
after-commit dispatch behavior once admission succeeds.

Database sealing does not encrypt an application's filesystem, cache, or queue
transport. Protect those systems independently and size attachment limits and
retention for the application's data policy.
