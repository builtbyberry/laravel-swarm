# Native Media & Retrieval Capabilities in Workflows

Laravel AI ships media and retrieval features as first-class native capabilities:
classification, image generation, audio (text-to-speech) and transcription,
provider files and vector stores, embeddings, and reranking. This guide shows how
to use them **from inside a Swarm workflow** — an application-owned native agent or
tool that calls the capability while the workflow carries its input (P1),
reconstructs its settings (P2), and exposes its typed result (P3). Prefer the
native call and a runnable example; no capability here needs a bespoke adapter.

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

## Execution-mode matrix

The capability call is mode-agnostic — it runs inside the tool loop. The outer
workflow chooses the mode:

| Topology | `prompt()` | `queue()` | `stream()` | `dispatchDurable()` |
|---|---|---|---|---|
| Sequential | yes | yes | yes | yes |
| Parallel | yes | yes | opt-in process multiplexing | yes |
| Hierarchical (generated / static) | yes | yes | yes | yes |

Attachment inputs and reconstructible references cross the queue/durable boundary
through the native-input transport (see [Native Messages and Attachments](native-inputs.md));
typed capability results surface on each completed step's native result (see
[Native Step Results](native-step-results.md)).

## Unsupported combinations (preserved, not worked around)

Provider/model limitations are Laravel AI's. Swarm surfaces them unchanged — an
unsupported capability fails loud before any provider call rather than silently
degrading. Choose a provider that offers the capability.

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

- **Large binaries stay out of the workflow payload.** A generated image is stored
  to an application disk (`ImageResponse::store()`); the workflow threads the stable
  path/reference, never the bytes. Provider files and vector-store documents are
  likewise carried as stable ids (`Files::put()` → id, `Stores::create()` → id).
- **Capture controls apply.** Native step results honour `swarm.capture.*` (Full /
  Redact / off) exactly as [Native Step Results](native-step-results.md) defines; the
  media bytes are never reintroduced into persisted rows by any capture setting.
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

## Proven by

Executable evidence, run against controlled native wire fixtures and native fakes
with no live provider:

- [NativeCapabilityWorkflowTest](../tests/Feature/Adoption/NativeCapabilityWorkflowTest.php) — every capability family runs inside a workflow; typed results surface; a genuine multi-step sequential pipeline.
- [NativeCapabilityWireTest](../tests/Feature/Adoption/NativeCapabilityWireTest.php) — real native embeddings wire request formation; modality failures propagate; an unsupported provider combination is preserved.
- [NativeCapabilityArtifactTest](../tests/Feature/Adoption/NativeCapabilityArtifactTest.php) — image bytes stay on disk and out of persisted payloads; capture-off does not re-capture bytes; an expired vector store is an actionable failure; `swarm:prune` leaves the application disk untouched.
- [NativeCapabilityConcurrencyTest](../tests/ProcessConcurrency/NativeCapabilityConcurrencyTest.php) — capability workflows run in real background processes with per-tenant isolation.
- [NativeCapabilityExampleTest](../tests/Feature/Adoption/NativeCapabilityExampleTest.php) — the starter runs end-to-end, offline.

The workflow substrate these run on is [SwarmRunner](../src/Runners/SwarmRunner.php);
typed capability results are exposed on [SwarmStep](../src/Responses/SwarmStep.php)
via [NativeStepResult](../src/Responses/NativeStepResult.php), and attachment inputs
travel through [NativeInputManager](../src/Support/NativeInputManager.php).

## Next step

- [native-media-retrieval starter](../stubs/examples/native-media-retrieval/README.md) — install and run the recipe.
- [Sequential Topology](sequential.md) · [Choosing an Execution Mode](execution-modes.md)
