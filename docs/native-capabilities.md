# Native Media & Retrieval Capabilities in Workflows

Laravel AI ships media and retrieval features as first-class native capabilities:
classification, image generation, audio (text-to-speech) and transcription,
provider files and vector stores, embeddings, and reranking. This guide shows how
to use them **from inside a Swarm workflow** — an application-owned native agent or
tool that calls the capability while the workflow carries its native input
([native message inputs & attachments](native-inputs.md)), reconstructs its native
agent settings across workers ([per-run settings](native-inputs.md)), and records
each step's bounded native result ([native step results](native-step-results.md)).
Prefer the native call and a runnable example; no capability here needs a bespoke
adapter.

The typed capability result (an `EmbeddingsResponse`, `RerankingResponse`, …) is
consumed **inside the calling tool**; the workflow step records the agent's own
bounded native result, not the nested capability object (see
[Artifacts, capture, and honest accounting](#artifacts-capture-and-honest-accounting)).

> **Scope.** This is a recipe and a proof index, not a new bridge. Every capability
> below is Laravel AI's own; Swarm composes it. The behaviour is proven against
> `laravel/ai` **v1.0.1** with controlled native wire fixtures and native fakes —
> no paid or live provider is ever called. Provider/model limitations are Laravel
> AI's and are preserved, not papered over.

## The pattern

An application tool implements `Laravel\Ai\Contracts\Tool` and calls the native
capability in `handle()`; a native agent (`Laravel\Ai\Contracts\Agent`) lists the
tool; a `Swarm` runs the agent. The capability therefore executes during the
agent's tool loop, inside the workflow:

```php
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Reranking;
use Laravel\Ai\Tools\Request;

class RerankTool implements Tool
{
    public function description(): string
    {
        return 'Rerank retrieval candidates for the query.';
    }

    public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $ranked = Reranking::of($this->candidates)->limit(3)->rerank($request['query']);

        return $ranked->first()?->document ?? '(none)';
    }
}
```

The runnable [native-media-retrieval starter](../stubs/examples/native-media-retrieval/README.md)
assembles the full plan → retrieve → answer pipeline; the tests below prove each
capability against a controlled native wire.

## Capability matrix

Each capability is a native Laravel AI entry point. All run inside a workflow tool
regardless of the outer execution mode (`prompt`, `queue`, `stream`, `durable`) and
topology, because the capability call happens in the agent's tool loop.

| Capability | Native entry point | Typed result | Default provider |
|---|---|---|---|
| Classification | `Classification::of()->question()->classify()` | `ClassificationResponse` (`ChoiceAnswer`, `BooleanAnswer`, `ScoreAnswer`) | typesafe |
| Image generation | `Image::of()->generate()` | `ImageResponse` / `GeneratedImage` | gemini |
| Vision input | `Files\Image` attachment on a `UserMessage` | (feeds the agent) | provider-dependent |
| Audio (TTS) | `Audio::of()->generate()` | `AudioResponse` | openai |
| Transcription (STT) | `Transcription::of()->generate()` | `TranscriptionResponse` | openai |
| Provider files | `Files::put()` / `Files::get()` | `StoredFileResponse` / `FileResponse` | openai |
| Vector stores | `Stores::create()` + `FileSearch` provider tool | `Store` / `AddedDocumentResponse` | openai |
| Embeddings | `Embeddings::for()->generate()` | `EmbeddingsResponse` | openai |
| Reranking | `Reranking::of()->rerank()` | `RerankingResponse` / `RankedDocument` | cohere |

## Execution modes

The capability call happens inside the agent's tool loop, so it is
**mode-agnostic by construction** — a tool that calls `Embeddings::for()` does the
same thing whether the workflow ran via `prompt()`, `queue()`, `stream()`, or
`dispatchDurable()`, across sequential, parallel and hierarchical topologies. The
outer mode is Swarm's, not the capability's, and is exercised in full by the
topology/mode components of this release ([P1](native-inputs.md),
[P7](parallel.md), [P8](native-chat-protocols.md), [P9](error-handling.md)).

This component's own executable coverage of the capability-in-a-tool path is:

| Path | Covered here |
|---|---|
| `prompt()`, single agent | yes — every capability family |
| `prompt()`, sequential multi-agent | yes — embeddings → reranking pipeline |
| `queue()` | yes — an embedding runs in a queued worker |
| `stream()` / `dispatchDurable()` / parallel / hierarchical | inherited: same tool-loop path, exercised by the mode/topology components above |

Attachment inputs and reconstructible references cross the queue/durable boundary
through the native-input transport (see [Native Messages and Attachments](native-inputs.md)).
Each completed step records the **agent's** bounded native result — provider,
model, generation steps, and tool-call status — via
[Native Step Results](native-step-results.md); the nested capability's typed
object is not placed on the step (it is consumed in the tool).

## Unsupported combinations (preserved, not worked around)

Provider/model limitations are Laravel AI's. Swarm surfaces them unchanged — an
unsupported capability fails loud before any provider call rather than silently
degrading. Choose a provider that offers the capability. The rows below are
verified against `laravel/ai` v1.0.1 gateway source; image generation on Anthropic
is additionally proven to fail loud **from inside a workflow tool loop**
([NativeCapabilityWireTest](../tests/Feature/Adoption/NativeCapabilityWireTest.php)).

| Capability | Not supported on | Behaviour |
|---|---|---|
| Image generation | Anthropic | `LogicException` — provider does not support image generation |
| Audio / Transcription | Anthropic, xAI | `LogicException` — provider does not support audio/transcription |
| Embeddings | xAI | `LogicException` — provider does not support embeddings |
| Document attachments | Groq, DeepSeek, OpenAI-compatible | `InvalidArgumentException` — unsupported attachment type |
| Remote / provider-stored attachments | Bedrock | `InvalidArgumentException` — unsupported attachment type |
| Diarized transcription | OpenRouter, Groq | unsupported — use OpenAI, ElevenLabs, Mistral, or Gemini |

When a provider list is configured, a recoverable provider error raises
`FailoverableException` and Laravel AI fails over to the next provider (emitting
`ProviderFailedOver`) before the capability call is abandoned.

## Artifacts, capture, and honest accounting

- **Keep large binaries out of the workflow payload — return a reference.** When a
  capability tool returns a stable reference rather than the bytes, the bytes never
  enter the workflow. A generated image is stored to an application disk
  (`ImageResponse::store()`) and the tool threads the path; provider files and
  vector-store documents are carried as stable ids (`Files::put()` → id,
  `Stores::create()` → id). A tool that instead returned base64 would persist those
  bytes verbatim under capture — so returning a reference is the rule this component
  demonstrates, not a guarantee Swarm imposes on arbitrary tool output.
- **Capture controls apply.** The step's native result honours `swarm.capture.*`
  exactly as [Native Step Results](native-step-results.md) defines. With capture off,
  the shipped-false flag maps to **Redact** — the step output and native result are
  redacted (proven in
  [NativeCapabilityArtifactTest](../tests/Feature/Adoption/NativeCapabilityArtifactTest.php)) —
  and, because the reference-returning tool never put bytes on the payload, no media
  bytes appear in persisted rows under Full or Redact.
- **Nested usage is accounted honestly.** A capability called inside a tool (for
  example `Embeddings::for()->generate()`) carries its **own** usage on its response
  object. Swarm never folds that usage into the outer agent's text-token total — the
  outer step usage is the text turn's tokens only. Account for a nested capability's
  usage at the tool/artifact layer (read `EmbeddingsResponse::$usage`,
  `RerankingResponse::$usage`, etc.); Swarm's aggregated total deliberately does not
  include it.
- **Cleanup ownership.** `swarm:prune` prunes only Swarm-created persistence and
  native-input temporary files. An application-selected artifact disk is the
  application's to retain and prune.
- **Permissions and tenant isolation.** A capability tool is stateless per call and
  adds no cross-tenant surface, so this component does not re-prove the transport's
  isolation. Attachment permissions (authorized, versioned file references) are the
  native-input contract's ([native message inputs](native-inputs.md)); tenant
  isolation of native settings, inputs, and results across interleaved and recovered
  runs is proven by the settings-reconstruction and step-result components. What this
  component proves is that concurrent capability workflows stay isolated across real
  background processes (see
  [NativeCapabilityConcurrencyTest](../tests/ProcessConcurrency/NativeCapabilityConcurrencyTest.php)).

## Proven by

Executable evidence, run against controlled native wire fixtures and native fakes
with no live provider:

- [NativeCapabilityWorkflowTest](../tests/Feature/Adoption/NativeCapabilityWorkflowTest.php) — classification, image generation, audio (TTS), transcription (STT), provider files (`put` + `get`) with vector stores, embeddings, and reranking each run inside a workflow tool loop and surface a typed result at the tool layer; a genuine multi-step sequential pipeline; and a guard that the nested typed result stays off the step.
- [NativeCapabilityModeTest](../tests/Feature/Adoption/NativeCapabilityModeTest.php) — a native capability runs on the `queue()` path, not only `prompt()`.
- [NativeCapabilityWireTest](../tests/Feature/Adoption/NativeCapabilityWireTest.php) — real native embeddings wire request formation; modality failures propagate; an unsupported provider combination is preserved, both directly and from inside a workflow.
- [NativeCapabilityArtifactTest](../tests/Feature/Adoption/NativeCapabilityArtifactTest.php) — image bytes stay on disk and off the wire/persisted payloads; capture-off redacts the step; an expired vector store is an actionable failure; `swarm:prune` leaves the application disk untouched.
- [NativeCapabilityConcurrencyTest](../tests/ProcessConcurrency/NativeCapabilityConcurrencyTest.php) — a capability workflow runs in a real background process, each branch producing its own result under process isolation.
- [NativeCapabilityExampleTest](../tests/Feature/Adoption/NativeCapabilityExampleTest.php) — the starter runs end-to-end, offline.

Vision input (an image `Files\Image` attachment on a `UserMessage`) is the native
input-attachment mechanism, proven end-to-end by
[native message inputs](native-inputs.md) rather than re-proven here.

The workflow substrate these run on is [SwarmRunner](../src/Runners/SwarmRunner.php).
Each completed [SwarmStep](../src/Responses/SwarmStep.php) records the agent's own
bounded projection via [NativeStepResult](../src/Responses/NativeStepResult.php)
(never the nested capability object), and attachment inputs travel through
[NativeInputManager](../src/Support/NativeInputManager.php).

## Next step

- [native-media-retrieval starter](../stubs/examples/native-media-retrieval/README.md) — install and run the recipe.
- [Sequential Topology](sequential.md) · [Choosing an Execution Mode](execution-modes.md)
