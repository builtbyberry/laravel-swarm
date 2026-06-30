<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm;

use BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy;
use BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Audit\DefaultActorResolver;
use BuiltByBerry\LaravelSwarm\Audit\NoOpAuditOutbox;
use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\RunAuditEmitter;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallAuditCommand;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallCommand;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallDurableCommand;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallExamplesCommand;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallMemoryCommand;
use BuiltByBerry\LaravelSwarm\Commands\Install\InstallPulseCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeMemoryToolCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmAgentCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmAuditReconcileCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmAuditStatusCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmCancelCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmCompactCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHealthCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHistoryCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmInspectCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryDumpCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryInspectCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmMemoryPurgeCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPauseCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmProgressCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPruneCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRecoverCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRelayCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmResumeCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmSignalCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmStatusCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmTraceCommand;
use BuiltByBerry\LaravelSwarm\Compaction\SwarmCompactor;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemorySnapshotRecorder;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseStreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\NullConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Memory\NullSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\NullStreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\RunContextMemoryReader;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\AuditOutbox as AuditOutboxCard;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\SwarmMemory as SwarmMemoryCard;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\SwarmSteps;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBoundaryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableChildSwarmCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableLifecycleController;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableManagerCollaboratorFactory;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurablePayloadCapture;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRecoveryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunContext;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunTerminalHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSequentialStepAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableStepAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableStepCheckpointCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableStepExecutionBuilder;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSwarmStarter;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableTopLevelParallelAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\Durable\QueuedHierarchicalDurableCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\LeaseManager;
use BuiltByBerry\LaravelSwarm\Runners\ParallelRunner;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Runners\SwarmGuardrailRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\ContextGrowthGovernor;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Telemetry\NoOpSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryEventListener;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Pulse\Pulse;
use Livewire\LivewireManager;
use Psr\Log\LoggerInterface;

class SwarmServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/swarm.php',
            'swarm',
        );

        $this->app->singleton(SwarmAuditSink::class, NoOpSwarmAuditSink::class);
        $this->app->singleton(ActorResolver::class, DefaultActorResolver::class);
        $this->app->singleton(CapturePolicy::class, BooleanCapturePolicy::class);
        $this->app->singleton(MemoryPropagationPolicy::class, function (Application $app): MemoryPropagationPolicy {
            /** @var class-string<MemoryPropagationPolicy> $class */
            $class = $app->make(ConfigRepository::class)->get('swarm.memory.propagation_policy', DefaultPropagationPolicy::class);

            return $app->make($class);
        });
        // Resolve ConversationPropagationPolicy with its configured transcript
        // view. The container honours this binding whether the policy is the
        // configured default (resolved via make($class) above) or selected per
        // swarm with the #[PropagationPolicy] attribute (resolved via make()
        // in AgentVisibleMemoryView::resolvePolicy()).
        $this->app->bind(ConversationPropagationPolicy::class, function (Application $app): ConversationPropagationPolicy {
            return new ConversationPropagationPolicy(
                (bool) $app->make(ConfigRepository::class)
                    ->get('swarm.memory.conversation_view.include_run_memory', false),
            );
        });
        $this->app->singleton(MemoryCapturePolicy::class, function (Application $app): MemoryCapturePolicy {
            /** @var class-string $class */
            $class = $app->make(ConfigRepository::class)->get('swarm.memory.capture_policy', DefaultMemoryCapturePolicy::class);

            $policy = $app->make($class);

            if (! $policy instanceof MemoryCapturePolicy) {
                throw new SwarmException(
                    "Memory capture policy [{$class}] must implement ".MemoryCapturePolicy::class.'.',
                );
            }

            return $policy;
        });
        $this->app->singleton(RunContextMemoryReader::class);
        $this->app->singleton(SinkFailureHandler::class, ConfiguredSinkFailureHandler::class);
        $this->app->singleton(AuditOutbox::class, function (Application $app): AuditOutbox {
            $driver = $app->make(ConfigRepository::class)->get('swarm.persistence.driver');

            return $driver === 'database'
                ? $app->make(DatabaseAuditOutbox::class)
                : $app->make(NoOpAuditOutbox::class);
        });
        $this->app->singleton(SwarmAuditDispatcher::class, function (Application $app): SwarmAuditDispatcher {
            return new SwarmAuditDispatcher(
                sink: $app->make(SwarmAuditSink::class),
                config: $app->make(ConfigRepository::class),
                failureHandler: $app->make(SinkFailureHandler::class),
                capture: $app->make(SwarmCapture::class),
                signer: $app->bound(SwarmAuditSigner::class) ? $app->make(SwarmAuditSigner::class) : null,
                outbox: $app->make(AuditOutbox::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(SwarmTelemetrySink::class, NoOpSwarmTelemetrySink::class);
        $this->app->singleton(SwarmTelemetryDispatcher::class);
        $this->app->singleton(PackageJobTelemetryState::class);

        // Bind the cipher with a lazy encrypter resolver rather than autowiring
        // the Encrypter into its constructor: resolving the encrypter throws
        // MissingAppKeyException when no APP_KEY is set, and package:discover
        // boots providers during a fresh `composer install` before a key exists
        // (#122). The resolver is invoked only when the cipher first seals/opens.
        $this->app->singleton(SwarmPersistenceCipher::class, fn (Application $app): SwarmPersistenceCipher => new SwarmPersistenceCipher(
            config: $app->make(ConfigRepository::class),
            encrypter: static fn (): Encrypter => $app->make(Encrypter::class),
            logger: $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(SwarmAttributeResolver::class);
        $this->app->singleton(ContextGrowthGovernor::class);
        $this->app->singleton(SequentialRunner::class);
        $this->app->singleton(SequentialStreamRunner::class);

        $this->app->singleton(ParallelRunner::class);

        $this->app->singleton(HierarchicalRunner::class);
        $this->app->singleton(StaticHierarchicalRunner::class);
        $this->app->singleton(StaticHierarchicalStreamRunner::class);
        $this->app->singleton(HierarchicalStreamRunner::class);
        $this->app->singleton(SwarmStepRecorder::class);
        $this->app->singleton(QueuedHierarchicalCoordinator::class);

        $this->app->singleton(SwarmGuardrailRunner::class);

        $this->app->singleton(RunAuditEmitter::class);
        $this->app->singleton(DispatchValidator::class);
        $this->app->singleton(LeaseManager::class);
        $this->app->singleton(SwarmRunner::class);
        $this->app->singleton(SwarmHistory::class);
        $this->app->singleton(SwarmEventRecorder::class);

        // Durable manager graph — single construction path.
        //
        // DurableManagerCollaboratorFactory::make() is the sole owner of the coherent
        // manager subgraph. It builds one DurableRunContext and one DurablePayloadCapture
        // and passes them into every collaborator, including DurableSignalHandler,
        // DurableRetryHandler, DurableRunInspector, and DurableRunRecorder.
        //
        // Singletons: factory (stateless orchestrator) + manager (entry point).
        //
        // Bind (transient): step-scoped collaborators built by the factory via makeWith.
        //   DurableRunContext and DurablePayloadCapture are bound so tests can resolve them
        //   directly when building collaborators in isolation; do NOT add mutable run-scoped
        //   state to either without revisiting factory ownership.
        //
        // Not registered: DurableSignalHandler, DurableRetryHandler, DurableRunInspector.
        //   These are built exclusively inside the factory and must not be registered as
        //   container singletons — their only valid lifetime is inside the manager graph.
        //
        // DurableRunRecorder is registered as bind (not singleton) so tests can intercept
        //   it before the first DurableSwarmManager resolution:
        //
        //   app()->bind(DurableRunRecorder::class, fn ($app, $params) => new Spy($params));
        //
        //   The factory passes ['runs' => $runContext] as $params when calling makeWith, so
        //   closure overrides receive the shared DurableRunContext instance if they need it.
        $this->app->singleton(DurableManagerCollaboratorFactory::class);
        $this->app->bind(DurablePayloadCapture::class);
        $this->app->bind(DurableJobDispatcher::class);
        $this->app->bind(DurableSwarmStarter::class);
        $this->app->bind(QueuedHierarchicalDurableCoordinator::class);
        $this->app->bind(DurableBoundaryCoordinator::class);
        $this->app->bind(DurableRunContext::class);
        $this->app->bind(DurableBranchCoordinator::class);
        $this->app->bind(DurableChildSwarmCoordinator::class);
        $this->app->bind(DurableLifecycleController::class);
        $this->app->bind(DurableRecoveryCoordinator::class);
        $this->app->bind(DurableHierarchicalCoordinator::class);
        $this->app->bind(DurableRunTerminalHandler::class);
        $this->app->bind(DurableTopLevelParallelAdvancer::class);
        $this->app->bind(DurableStepExecutionBuilder::class);
        $this->app->bind(DurableSequentialStepAdvancer::class);
        $this->app->bind(DurableNodeStreamRecorder::class);
        $this->app->bind(DurableStepCheckpointCoordinator::class);
        $this->app->bind(DurableStepAdvancer::class);
        $this->app->bind(DurableBranchAdvancer::class);
        $this->app->bind(DurableRunRecorder::class);
        $this->app->singleton(DurableSwarmManager::class);
        $this->app->singleton(DurableRunStore::class, DatabaseDurableRunStore::class);
        $this->app->singleton(DurableOutbox::class, DatabaseDurableOutbox::class);

        $this->app->singleton(ContextStore::class, fn (Application $app): ContextStore => $this->resolvePersistenceStore(
            $app,
            'context',
            CacheContextStore::class,
            DatabaseContextStore::class,
        ));
        $this->app->singleton(ArtifactRepository::class, fn (Application $app): ArtifactRepository => $this->resolvePersistenceStore(
            $app,
            'artifacts',
            CacheArtifactRepository::class,
            DatabaseArtifactRepository::class,
        ));
        $this->app->singleton(RunHistoryStore::class, fn (Application $app): RunHistoryStore => $this->resolvePersistenceStore(
            $app,
            'history',
            CacheRunHistoryStore::class,
            DatabaseRunHistoryStore::class,
        ));
        // The database stream-event store IS the causal log (#282). Register it as
        // one shared singleton so the StreamEventStore DB branch and the
        // CausalLogStore contract resolve the same instance — never two.
        $this->app->singleton(DatabaseCausalLogStore::class);

        // Hot/cold tiering (#286). The cold archive driver is stateless and safe as
        // a singleton. TieredStreamEventStore decorates the hot DatabaseCausalLogStore
        // with the cold driver; its events() method stitches the two halves at the
        // base pointer using the half-open seam [0, base) / [base, ∞).
        $this->app->singleton(DatabaseColdArchiveDriver::class);
        $this->app->singleton(SwarmCompactor::class);
        $this->app->singleton(ColdArchiveDriver::class, DatabaseColdArchiveDriver::class);
        $this->app->singleton(TieredStreamEventStore::class, fn (Application $app): TieredStreamEventStore => new TieredStreamEventStore(
            hot: $app->make(DatabaseCausalLogStore::class),
            cold: $app->make(DatabaseColdArchiveDriver::class),
        ));

        $this->app->singleton(StreamEventStore::class, fn (Application $app): StreamEventStore => $this->resolvePersistenceStore(
            $app,
            'streaming.replay',
            CacheStreamEventStore::class,
            TieredStreamEventStore::class,
        ));

        // CausalLogStore is database-only: void-edges need indexed UUID lookup and
        // row locking the cache driver cannot provide. It always resolves the
        // database store; under the cache driver, appendVoidEdge() fails loud via
        // assertReady() rather than emitting a raw query error.
        $this->app->singleton(CausalLogStore::class, DatabaseCausalLogStore::class);

        $this->app->singleton(MemoryStore::class, fn (Application $app): MemoryStore => $this->resolvePersistenceStore(
            $app,
            'memory',
            CacheMemoryStore::class,
            DatabaseMemoryStore::class,
        ));

        // Wrap whatever MemoryStore is bound — the bundled drivers OR a
        // consumer/companion driver re-bound later — in the redaction decorator,
        // so the capture policy governs every write at a single chokepoint that
        // cannot be bypassed by rebinding the store. Reads pass through, so the
        // propagation view and frozen snapshots inherit redaction for free.
        // (A store registered via Container::instance() bypasses extenders; bind
        // custom drivers, don't instance() them — see UPGRADING.md.)
        $this->app->extend(MemoryStore::class, fn (MemoryStore $store, Application $app): MemoryStore => new RedactingMemoryStore(
            inner: $store,
            policy: $app->make(MemoryCapturePolicy::class),
            events: $app->make(Dispatcher::class),
        ));
        // The live store is bound (not a singleton) under its concrete name so
        // each resolution rebuilds the thin DefaultSwarmMemory coordinator around
        // the *current* MemoryStore binding — the run-state data lives in the
        // store driver (cache/database), not in this stateless facade, so there is
        // no instance to share. A singleton here would pin the first-resolved
        // MemoryStore reference, so a consumer that later rebinds MemoryStore
        // (e.g. to install a capture policy or swap drivers) would keep writing
        // through the stale chain. The SwarmMemory contract resolves to the
        // per-invocation frozen-view override when a crash-resume replay is active
        // on the current ActiveRunContext frame, else to that live store. This is
        // what lets *any* consumer that resolves SwarmMemory from the container —
        // including agents that read `app(SwarmMemory::class)` directly during a
        // durable retry — observe the frozen view, WITHOUT a process-global rebind
        // (so concurrent in-process runs under Octane each see their own frame's
        // view). The override never wraps itself: MemoryReplayCoordinator builds
        // its ReplaySwarmMemory from the concrete DefaultSwarmMemory binding, not
        // this contract resolution.
        $this->app->bind(DefaultSwarmMemory::class);
        $this->app->bind(
            SwarmMemory::class,
            fn (Application $app): SwarmMemory => ActiveRunContext::currentMemory() ?? $app->make(DefaultSwarmMemory::class),
        );

        // Default conversation→runs resolver is the honest no-op: Swarm records
        // no run/conversation link in v0.10, so `swarm:memory:dump` of a
        // conversation expands to no runs unless an application binds its own
        // resolver in place of this one.
        $this->app->singleton(ConversationRunResolver::class, NullConversationRunResolver::class);

        // Snapshot recording requires the `swarm_memory_snapshots` table from
        // migration #110. When persistence runs in `cache` mode (default for
        // tests and ephemeral workloads) the table is not migrated, so fall
        // back to a no-op implementation rather than failing every run. The
        // database recorder is wired automatically when `swarm.persistence.driver`
        // is `database` or `swarm.memory.driver` overrides it explicitly.
        $this->app->singleton(SnapshotsMemory::class, fn (Application $app): SnapshotsMemory => $this->resolvePersistenceStore(
            $app,
            'memory',
            NullSnapshotsMemory::class,
            DatabaseMemorySnapshotRecorder::class,
        ));

        // Per-step output checkpoints for idempotent multi-step resume of
        // non-durable streamed runs (issue #202). Bound in lockstep with
        // SnapshotsMemory off the same `memory` driver: a resume needs both the
        // frozen snapshot AND the checkpoint to be database-backed, so cache
        // mode falls back to the no-op store and every non-final step
        // re-executes on resume (the pre-#202 behaviour).
        $this->app->singleton(StreamStepCheckpointStore::class, fn (Application $app): StreamStepCheckpointStore => $this->resolvePersistenceStore(
            $app,
            'memory',
            NullStreamStepCheckpointStore::class,
            DatabaseStreamStepCheckpointStore::class,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (LaravelSwarm::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->make('config')->get('swarm.observability.listen_to_events', true)) {
            Event::subscribe(SwarmTelemetryEventListener::class);
        }

        $this->registerOctaneStateReset();

        if (class_exists(Pulse::class)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'swarm');

            $this->callAfterResolving('livewire', function (LivewireManager $livewire): void {
                $livewire->component('swarm.runs', SwarmRuns::class);
                $livewire->component('swarm.steps', SwarmSteps::class);
                $livewire->component('swarm.audit-outbox', AuditOutboxCard::class);
                $livewire->component('swarm.memory', SwarmMemoryCard::class);
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/swarm.php' => config_path('swarm.php'),
            ], 'swarm-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'swarm-migrations');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs'),
            ], 'swarm-stubs');

            $this->commands([
                MakeSwarmCommand::class,
                MakeSwarmSwarmCommand::class,
                MakeSwarmAgentCommand::class,
                MakeMemoryToolCommand::class,
                SwarmHealthCommand::class,
                SwarmPruneCommand::class,
                SwarmMemoryPurgeCommand::class,
                SwarmStatusCommand::class,
                SwarmHistoryCommand::class,
                SwarmPauseCommand::class,
                SwarmResumeCommand::class,
                SwarmCancelCommand::class,
                SwarmRecoverCommand::class,
                SwarmCompactCommand::class,
                SwarmRelayCommand::class,
                SwarmAuditStatusCommand::class,
                SwarmAuditReconcileCommand::class,
                SwarmSignalCommand::class,
                SwarmInspectCommand::class,
                SwarmMemoryInspectCommand::class,
                SwarmMemoryDumpCommand::class,
                SwarmProgressCommand::class,
                SwarmTraceCommand::class,
                InstallCommand::class,
                InstallExamplesCommand::class,
                InstallMemoryCommand::class,
                InstallPulseCommand::class,
                InstallAuditCommand::class,
                InstallDurableCommand::class,
            ]);
        }

    }

    /**
     * Flush the process-local {@see ActiveRunContext} stack on every Octane
     * worker reset, so a stale frame left by an abnormally-terminated run
     * (hard timeout, fatal) never outlives the operation that produced it.
     *
     * Opt-in by presence: the listener is wired only when `laravel/octane`'s
     * {@see OperationTerminated} contract is loadable,
     * so non-Octane apps see zero behaviour change and we never hard-depend on
     * the package. The contract is implemented by every terminated event
     * (request, task, tick), so one binding covers all three reset points.
     */
    protected function registerOctaneStateReset(): void
    {
        $operationTerminated = 'Laravel\Octane\Contracts\OperationTerminated';

        if (! interface_exists($operationTerminated)) {
            return;
        }

        Event::listen($operationTerminated, static function (): void {
            ActiveRunContext::flush();
        });
    }

    /**
     * @template TStore of object
     *
     * @param  class-string<TStore>  $cacheStore
     * @param  class-string<TStore>  $databaseStore
     * @return TStore
     */
    protected function resolvePersistenceStore(Application $app, string $configKey, string $cacheStore, string $databaseStore): object
    {
        $config = $app->make(ConfigRepository::class);
        $driver = $config->get("swarm.{$configKey}.driver");

        if (blank($driver)) {
            $driver = $config->get('swarm.persistence.driver', 'cache');
        }

        if (! in_array($driver, ['cache', 'database'], true)) {
            throw new \InvalidArgumentException(
                "Laravel Swarm: invalid persistence driver [{$driver}]. Supported drivers: cache, database.",
            );
        }

        return $app->make($driver === 'database' ? $databaseStore : $cacheStore);
    }
}
