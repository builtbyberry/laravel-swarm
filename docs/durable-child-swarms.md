# Durable Child Swarms

Child swarms let a durable parent run start another durable swarm and retain
lineage for inspection. The child is a normal durable swarm run with its own run
ID, history, labels, details, progress, and terminal state.

```php
$child = app(DurableSwarmManager::class)->dispatchChildSwarm(
    parentRunId: $parentRunId,
    childSwarmClass: ReviewSwarm::class,
    task: ['document_id' => $documentId],
);
```

The parent-child relation is stored in `swarm_durable_child_runs`. Future UI
surfaces should use that lineage instead of inferring relationships from prompt
text or labels.

Dispatching a child checkpoints the parent into a durable child wait. When the
child reaches a terminal state, recovery reconciles the child row, writes the
terminal status to parent metadata at `durable_child_runs.{childRunId}`, releases
the parent wait, and dispatches the parent next step. Child output and failure
details stay on the child lineage row and the child run history instead of being
copied into the parent runtime context.

Parent cancellation cancels active child durable runs by default. Child outputs
and failures are sensitive and follow the same capture, redaction, and pruning
rules as other durable operational records.
