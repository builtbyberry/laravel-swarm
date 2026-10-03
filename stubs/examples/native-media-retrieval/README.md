# Native Media & Retrieval

A starter that shows how to **structure** native retrieval — embeddings, vector
stores and reranking — inside a single Swarm workflow to answer a question from a
knowledge base. It runs offline with scripted stand-ins (no provider, no API key);
each agent's docblock and the "Plug in a real model" section below show the native
calls to drop in. Three agents run in order:

```
QueryPlanner → RetrievalAgent → AnswerSynthesizer
```

`QueryPlanner` rewrites the question into a focused retrieval query.
`RetrievalAgent` is where retrieval happens — in production it embeds the query,
searches a native vector store (via the `FileSearch` provider tool) and reranks the
candidates; the shipped stand-in scores a small in-memory passage set so the demo
runs offline. `AnswerSynthesizer` writes the final answer grounded in the top passage.

## Run it

```bash
php artisan swarm:example:media-retrieval "What execution modes do Swarm workflows run in?"
```

You should see a grounded answer built from the retrieved passages, plus the
per-step trace in `$response->steps`.

## What it demonstrates

- **How to structure native retrieval inside a workflow**, the headline: the
  plan → retrieve → answer shape where embeddings, vector stores and reranking are
  Laravel AI's own capabilities (`Embeddings::for(...)`, `Stores::create(...)` + the
  `FileSearch` provider tool, `Reranking::of(...)`), composed by a Swarm rather than
  reimplemented. This starter runs offline with scripted stand-ins; the executable
  proof that the native calls run in a workflow lives in the adoption tests (see
  docs/native-capabilities.md → "Proven by").
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

Inside `RetrievalAgent`, replace the offline scoring with the native path. The
idiomatic option binds a native vector store to the agent with the `FileSearch`
provider tool and lets the model retrieve:

```php
use Laravel\Ai\Providers\Tools\FileSearch;

public function tools(): array
{
    return [new FileSearch([config('services.kb.store_id')])];
}
```

Or retrieve app-side and rerank candidates you fetch yourself:

```php
$vector = Embeddings::for([$query])->generate()->first();
$candidates = $this->fetchCandidates($vector);   // your app's ANN/SQL search
$ranked = Reranking::of($candidates)->limit(3)->rerank($query);
```

No native capability needs a bespoke adapter — see the recipe and the support
matrix in the docs below.

## Next step

- [docs/native-capabilities.md](../../../docs/native-capabilities.md) — the full native media & retrieval recipe and support matrix.
- [docs/sequential.md](../../../docs/sequential.md) — the full sequential topology contract.
