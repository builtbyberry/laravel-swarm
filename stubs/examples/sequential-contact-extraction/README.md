# Sequential Contact Extraction

Pull a typed, schema-validated record out of unstructured text — structured
output, not free-form prose. Two agents run in order:

```
FieldExtractor → RecordNormalizer
```

`FieldExtractor` reads a messy blurb and emits the raw fields as a JSON object.
`RecordNormalizer` validates and canonicalises that object into a trusted
contact record. Both agents declare a Laravel AI structured-output schema.

## Run it

```bash
php artisan swarm:example:contact-extraction "Hi, this is Ada Lovelace — reach me at ada@analytical-engines.example or 555 0100."
```

You should see a validated JSON record from `RecordNormalizer` — with a `valid`
flag and a `missing` list — not a paragraph of text. The extractor's raw JSON
is recorded in `$response->steps`.

## What it demonstrates

- **Structured output**, the headline feature: each agent implements Laravel
  AI's `HasStructuredOutput` and declares a `schema(JsonSchema $schema): array`.
  A real provider is then constrained to return a single parsed object matching
  that schema, never free text.
- The classic **extract → validate** shape: the extractor pulls fields, the
  normaliser validates and canonicalises them into a record you can trust.
- Sequential topology — each agent's structured output is the next agent's input.
- The `Runnable` trait and `Swarm::make()->prompt(...)` execution.
- The `ScriptedAgent` base class from `BuiltByBerry\LaravelSwarm\Testing`, so the
  example runs end-to-end with no provider configured and no API key. The
  scripted replies return the same JSON a structured provider would.

Because the swarm runs via `prompt()` (never streamed), the structured-output
agents are on a fully supported path — the `stream()` guard for
`HasStructuredOutput` agents (see below) never applies here.

## Plug in a real model

Generate each native schema-backed agent through Laravel AI, then port the
starter's instructions and schema:

```bash
php artisan make:agent FieldExtractor --structured
php artisan make:agent RecordNormalizer --structured
```

> **Note:** a `HasStructuredOutput` agent cannot be *streamed* — it produces one
> parsed object, not a token stream. Run structured-output nodes with
> `run()` / `prompt()`, not `stream()` or `#[DurableStreaming]`.

## Next step

- [docs/sequential.md](../../../docs/sequential.md) — the full sequential topology contract.
- [docs/execution-modes.md](../../../docs/execution-modes.md) — when to move beyond `prompt()`.
