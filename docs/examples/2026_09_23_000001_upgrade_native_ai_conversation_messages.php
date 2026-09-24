<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

// Application-owned example. Read ../native-conversation-upgrade.md before copying.
return new class extends AiMigration
{
    private const int MAX_MESSAGES_PER_CONVERSATION = 10_000;

    public function up(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumns($table, ['tool_calls', 'tool_results', 'approval_state'])
            || $schema->hasColumn($table, 'steps') || $schema->hasColumn($table, 'status')) {
            throw new RuntimeException('Expected the complete old native schema. Inspect the stopped migration and restore the coordinated backup before retrying.');
        }

        // All writers must be stopped. Validate EVERY conversation before DDL;
        // MySQL DDL cannot be made atomic with the following backfill transactions.
        $this->eachConversation($table, fn (string $id) => $this->converted($table, $id));

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->default('completed');
        });

        $this->eachConversation($table, function (string $id) use ($table): void {
            DB::connection($this->getConnection())->transaction(function () use ($table, $id): void {
                foreach ($this->converted($table, $id) as $rowId => $values) {
                    $this->query($table)->where('id', $rowId)->update($values);
                }
            });
        });

        if ($this->query($table)->whereNull('steps')->exists()) {
            throw new RuntimeException('Native backfill is incomplete. Keep writers stopped and restore the coordinated backup.');
        }

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->longText('steps')->nullable(false)->change();
            $blueprint->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
            $blueprint->dropIndex('participant_index');
            $blueprint->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Dropped native evidence cannot be reconstructed. Restore the coordinated database/code/dependency backup and reconcile later effects.');
    }

    private function eachConversation(string $table, callable $callback): void
    {
        $this->query($table)->select('conversation_id')->distinct()->orderBy('conversation_id')
            ->chunk(100, function ($conversations) use ($callback): void {
                foreach ($conversations as $conversation) {
                    $callback($conversation->conversation_id);
                }
            });
    }

    /** @return array<string, array{steps: string, meta?: string}> */
    private function converted(string $table, string $conversationId): array
    {
        $rows = $this->query($table)->where('conversation_id', $conversationId)->orderBy('id')
            ->limit(self::MAX_MESSAGES_PER_CONVERSATION + 1)->get();
        if ($rows->count() > self::MAX_MESSAGES_PER_CONVERSATION) {
            throw new RuntimeException("Native conversation {$conversationId} exceeds the 10,000-message safety ceiling. Rehearse and split or adapt the application-owned migration before conversion.");
        }
        $calls = [];
        $pendingById = [];
        $metadata = [];

        foreach ($rows as $row) {
            $state = $row->approval_state === null ? null : $this->decoded($row->approval_state, $row->id);
            if ($state !== null && (! is_array($state) || ! array_key_exists('pending', $state)
                || ! is_array($state['pending']) || $state['pending'] !== [])) {
                throw new RuntimeException("Native message {$row->id} has unresolved or invalid approval state. Resolve or abandon the turn in the old application before conversion.");
            }
            if (! in_array($row->role, ['user', 'assistant'], true)) {
                throw new RuntimeException("Unsupported native message role at {$row->id}; review manually before conversion.");
            }
            $rowCalls = $this->list($row->tool_calls, $row->id);
            $rowResults = $this->list($row->tool_results, $row->id);
            $meta = $this->decoded($row->meta, $row->id);
            if (! is_array($meta) || (isset($meta['reasoning']) && ! is_string($meta['reasoning']))) {
                throw new RuntimeException("Invalid native metadata at {$row->id}; review manually before conversion.");
            }
            $metadata[$row->id] = $meta;
            if ($row->role === 'user' && ($rowCalls !== [] || $rowResults !== [])) {
                throw new RuntimeException("Unexpected native user tool evidence at {$row->id}; review manually before conversion.");
            }

            $own = [];
            foreach ($rowCalls as $call) {
                $this->validateTool($call, $row->id, false);
                $own['id:'.$call['id']] = true;
            }
            $ownResults = [];
            foreach ($rowResults as $result) {
                $this->validateTool($result, $row->id, true);
                if (isset($own['id:'.$result['id']])) {
                    // Match the old reader's row-local last-result semantics.
                    $ownResults['id:'.$result['id']] = $result;
                } else {
                    $matches = $pendingById['id:'.$result['id']] ?? [];
                    if (count($matches) !== 1) {
                        throw new RuntimeException("Native result ownership is ambiguous or missing at message {$row->id}; review the backup manually before conversion.");
                    }
                    $calls[$matches[0]]['result'] = $result;
                    unset($pendingById['id:'.$result['id']]);
                }
            }
            foreach ($rowCalls as $call) {
                $index = count($calls);
                $result = $ownResults['id:'.$call['id']] ?? null;
                $calls[] = ['row' => $row->id, 'call' => $call, 'result' => $result];
                if ($result === null) {
                    $pendingById['id:'.$call['id']][] = $index;
                }
            }
        }

        $answered = [];
        foreach ($calls as $item) {
            if ($item['result'] === null) {
                continue; // The documented upstream conversion drops unanswered calls.
            }
            $result = $item['result'];
            $call = $item['call'];
            foreach (['name', 'arguments', 'result_id', 'result', 'denied', 'failed'] as $field) {
                if (array_key_exists($field, $result)) {
                    $call[$field] = $result[$field];
                }
            }
            $answered[$item['row']][] = $call;
        }

        $converted = [];
        foreach ($rows as $row) {
            if ($row->role === 'user') {
                $converted[$row->id] = ['steps' => '[]'];

                continue;
            }
            $meta = $metadata[$row->id];
            $rowCalls = $answered[$row->id] ?? [];
            $reasoning = $meta['reasoning'] ?? '';
            $steps = $rowCalls !== [] && $row->content !== ''
                ? [$this->step('', $rowCalls), $this->step($row->content, [], $reasoning)]
                : [$this->step($row->content, $rowCalls, $reasoning)];
            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);
            $converted[$row->id] = [
                'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            ];
        }

        return $converted;
    }

    private function validateTool(mixed $tool, string $row, bool $result): void
    {
        if (! is_array($tool) || ! isset($tool['id'], $tool['name'], $tool['arguments'])
            || ! is_string($tool['id']) || $tool['id'] === '' || ! is_string($tool['name'])
            || ! is_array($tool['arguments']) || ($result && ! array_key_exists('result', $tool))) {
            throw new RuntimeException("Invalid native tool evidence at {$row}; review manually before conversion.");
        }
        foreach (['denied', 'failed'] as $flag) {
            if (array_key_exists($flag, $tool) && ! is_bool($tool[$flag])) {
                throw new RuntimeException("Invalid native tool flags at {$row}; review manually before conversion.");
            }
        }
    }

    private function list(string $value, string $row): array
    {
        $decoded = $this->decoded($value, $row);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Invalid native tool list at {$row}; review manually before conversion.");
        }

        return $decoded;
    }

    private function decoded(string $value, string $row): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Invalid native JSON at {$row}; review manually before conversion.");
        }
    }

    private function step(string $content, array $calls, string $reasoning = ''): array
    {
        return ['content' => $content, 'tool_calls' => $calls, 'reasoning' => $reasoning, 'replay_blocks' => [], 'provider_tool_calls' => []];
    }

    private function query(string $table): Builder
    {
        return DB::connection($this->getConnection())->table($table);
    }
};
