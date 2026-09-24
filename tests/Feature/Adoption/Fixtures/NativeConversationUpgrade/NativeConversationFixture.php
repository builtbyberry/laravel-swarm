<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeConversationUpgrade;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

final class NativeConversationFixture
{
    public const CONVERSATIONS = 'c2_conversations';

    public const MESSAGES = 'c2_messages';

    public Migrator $migrator;

    public function __construct()
    {
        $native = config('database.connections.testing');
        config()->set('database.connections.c2_native', $native);
        config()->set('database.connections.c2_default', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('c2_native');
        DB::purge('c2_default');
        DB::setDefaultConnection('c2_default');
        config()->set('ai.conversations.connection', 'c2_native');
        config()->set('ai.conversations.tables.conversations', self::CONVERSATIONS);
        config()->set('ai.conversations.tables.messages', self::MESSAGES);
        $this->drop('c2_native');
        $repository = new DatabaseMigrationRepository(app('db'), 'c2_migrations');
        $repository->setSource('c2_default');
        $repository->createRepository();
        $this->migrator = new Migrator($repository, app('db'), app('files'), app('events'));
        $this->default()->statement('create table c2_sentinel (value varchar(40))');
        $this->default()->table('c2_sentinel')->insert(['value' => 'untouched default']);
    }

    public function native(): Connection
    {
        return DB::connection('c2_native');
    }

    public function default(): Connection
    {
        return DB::connection('c2_default');
    }

    public function fresh(): void
    {
        $this->run(base_path('vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php'));
    }

    public function legacy(): void
    {
        $this->run(__DIR__.'/2026_01_11_000001_create_agent_conversations_table.php');
    }

    public function upgrade(): void
    {
        $this->run(dirname(__DIR__, 5).'/docs/examples/2026_09_23_000001_upgrade_native_ai_conversation_messages.php');
    }

    public function run(string $path): void
    {
        $this->migrator->run([$path]);
    }

    public function snapshot(string $connection = 'c2_native'): array
    {
        $db = DB::connection($connection);

        return [
            'conversations' => $db->table(self::CONVERSATIONS)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'messages' => $db->table(self::MESSAGES)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function restore(array $backup): void
    {
        $this->drop('c2_native');
        $this->default()->table('c2_migrations')->delete();
        $this->legacy();
        $this->native()->transaction(function () use ($backup): void {
            foreach (['conversations' => self::CONVERSATIONS, 'messages' => self::MESSAGES] as $key => $table) {
                foreach ($backup[$key] as $row) {
                    $this->native()->table($table)->insert($row);
                }
            }
        });
    }

    public function cleanup(): void
    {
        $this->drop('c2_native');
        DB::purge('c2_native');
        DB::purge('c2_default');
        DB::setDefaultConnection('testing');
    }

    private function drop(string $connection): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $schema->dropIfExists(self::MESSAGES);
        $schema->dropIfExists(self::CONVERSATIONS);
    }

    public static function id(int $number): string
    {
        return '00000000-0000-0000-0000-'.sprintf('%012d', $number);
    }

    public static function row(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => self::id($id), 'conversation_id' => self::id(1),
            'participant_type' => 'fixture-user', 'participant_id' => 17,
            'agent' => 'FixtureAgent', 'role' => 'assistant', 'content' => 'answer — café',
            'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]',
            'usage' => '{"prompt_tokens":12,"completion_tokens":3}', 'meta' => '{"custom":"保持"}',
            'approval_state' => null, 'created_at' => '2026-09-20 12:00:00', 'updated_at' => '2026-09-20 12:01:00',
        ], $overrides);
    }

    public function seed(): void
    {
        $this->native()->table(self::CONVERSATIONS)->insert([
            'id' => self::id(1), 'participant_type' => 'fixture-user', 'participant_id' => 17,
            'title' => '会話 — café', 'created_at' => '2026-09-20 12:00:00', 'updated_at' => '2026-09-20 12:01:00',
        ]);
        $call = fn (string $id, array $arguments = ['value' => 'proposed']) => ['id' => $id, 'name' => 'FixtureTool', 'arguments' => $arguments, 'result_id' => 'proposed-result'];
        $result = fn (string $id, mixed $value, array $extra = []) => array_replace($call($id), ['result' => $value, 'result_id' => 'executed-result'], $extra);
        $json = fn (array $value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $rows = [
            self::row(1, ['role' => 'user', 'content' => '質問 — café', 'attachments' => '[{"kind":"fixture","name":"保持.txt"}]']),
            self::row(2, ['tool_calls' => $json([$call('repeat')]), 'tool_results' => $json([$result('repeat', 'first')])]),
            self::row(3, ['tool_calls' => $json([$call('repeat')]), 'tool_results' => $json([$result('repeat', 'second')])]),
            self::row(4, ['tool_calls' => $json([$call('denied')]), 'tool_results' => $json([$result('denied', 'no', ['denied' => true, 'failed' => false])])]),
            self::row(5, ['tool_calls' => $json([$call('failed')]), 'tool_results' => $json([$result('failed', 'error', ['failed' => true, 'denied' => false])])]),
            self::row(6, ['tool_calls' => $json([$call('later')])]),
            self::row(7, ['tool_results' => $json([$result('later', 'later answer')])]),
            self::row(8, ['tool_calls' => $json([$call('edited')]), 'tool_results' => $json([$result('edited', 'edited answer', ['name' => 'ExecutedTool', 'arguments' => ['value' => 'executed']])]), 'approval_state' => '{"pending":[]}', 'meta' => '{"custom":"保持","reasoning":"考え","provider_steps":["opaque"],"provider_content_blocks":["opaque"]}']),
            self::row(9, ['tool_calls' => $json([$call('null')]), 'tool_results' => $json([$result('null', null)])]),
            self::row(10, ['content' => str_repeat('x', 40000).'終', 'tool_calls' => $json([$call('large')]), 'tool_results' => $json([$result('large', str_repeat('y', 45000).'終')])]),
            self::row(11, ['tool_calls' => $json([$call('unanswered')])]),
        ];
        foreach ($rows as $row) {
            $this->native()->table(self::MESSAGES)->insert($row);
        }
    }
}
