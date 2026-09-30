# Native Media & Retrieval

Answer a question from a knowledge base using Laravel AI's **native** retrieval
capabilities — embeddings, vector stores and reranking — all driven from inside a
single Swarm workflow. Three agents run in order:

```
QueryPlanner → RetrievalAgent → AnswerSynthesizer
```

`QueryPlanner` rewrites the question into a focused retrieval query.
`RetrievalAgent` embeds that query, searches a native vector store and reranks the
candidates, returning the winning passages. `AnswerSynthesizer` writes the final
answer grounded in the top passage.

## Run it

```bash
php artisan swarm:example:media-retrieval "What execution modes do Swarm workflows run in?"
```

You should see a grounded answer built from the retrieved passages, plus the
per-step trace in `$response->steps`.

## What it demonstrates

- **Native retrieval inside a workflow**, the headline: embeddings, vector stores
  and reranking are Laravel AI's own capabilities (`Embeddings::for(...)`,
  `Stores::create(...)` + the `FileSearch` provider tool, `Reranking::of(...)`),
  composed by a Swarm rather than reimplemented.
- The classic **plan → retrieve → answer** shape, with each agent consuming the
  previous agent's output (Sequential topology).
- The `Runnable` trait and `Swarm::make()->prompt(...)` execution.
- The `ScriptedAgent` base class from `BuiltByBerry\LaravelSwarm\Testing`, so the
  example runs end-to-end with no provider configured and no API key. The scripted
  replies stand in for the native calls the docblocks show you how to drop in.

## Plug in a real model

Generate the agents through Laravel AI, then port the starter's instructions and
wire in the native retrieval calls shown in each agent's docblock:

```bash
php artisan make:agent QueryPlanner
php artisan make:agent RetrievalAgent
php artisan make:agent AnswerSynthesizer
```

Inside `RetrievalAgent`, replace the offline scoring with the native path:

```php
$vector = Embeddings::for([$query])->generate()->first();
$store = Stores::get(config('services.kb.store_id'));
$ranked = Reranking::of($candidates)->limit(3)->rerank($query);
```

No native capability needs a bespoke adapter — see the recipe and the tested
feature/mode/provider matrix in the docs below.

## Next step

- [docs/native-capabilities.md](../../../docs/native-capabilities.md) — the full native media & retrieval recipe and support matrix.
- [docs/sequential.md](../../../docs/sequential.md) — the full sequential topology contract.
