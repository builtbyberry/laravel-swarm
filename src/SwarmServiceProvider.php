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
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmAgentCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmSwarmCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmAuditReconcileCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmAuditStatusCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmCancelCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHealthCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmHistoryCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmInspectCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPauseCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmProgressCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmPruneCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRecoverCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmRelayCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmResumeCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmSignalCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmStatusCommand;
use BuiltByBerry\LaravelSwarm\Commands\SwarmTraceCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemorySnapshotRecorder;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\NullSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseAuditOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\AuditOutbox as AuditOutboxCard;
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
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Telemetry\NoOpSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryEventListener;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
                signer: $app->bound(SwarmAuditSigner::class) ? $app->make(SwarmAuditSigner::class) : null,
                outbox: $app->make(AuditOutbox::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(SwarmTelemetrySink::class, NoOpSwarmTelemetrySink::class);
        $this->app->singleton(SwarmTelemetryDispatcher::class);
        $this->app->singleton(PackageJobTelemetryState::class);

        $this->app->singleton(SwarmPersistenceCipher::class);
        $this->app->singleton(SwarmAttributeResolver::class);
        $this->app->singleton(SequentialRunner::class);
        $this->app->singleton(SequentialStreamRunner::class);

        $this->app->singleton(ParallelRunner::class);

        $this->app->singleton(HierarchicalRunner::class);
        $this->app->singleton(StaticHierarchicalRunner::class);
        $this->app->singleton(StaticHierarchicalStreamRunner::class);
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
        $this->app->singleton(StreamEventStore::class, fn (Application $app): StreamEventStore => $this->resolvePersistenceStore(
            $app,
            'streaming.replay',
            CacheStreamEventStore::class,
            DatabaseStreamEventStore::class,
        ));

        $this->app->singleton(MemoryStore::class, fn (Application $app): MemoryStore => $this->resolvePersistenceStore(
            $app,
            'memory',
            CacheMemoryStore::class,
            DatabaseMemoryStore::class,
        ));
        $this->app->singleton(SwarmMemory::class, DefaultSwarmMemory::class);

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

        if (class_exists(Pulse::class)) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'swarm');

            $this->callAfterResolving('livewire', function (LivewireManager $livewire): void {
                $livewire->component('swarm.runs', SwarmRuns::class);
                $livewire->component('swarm.steps', SwarmSteps::class);
                $livewire->component('swarm.audit-outbox', AuditOutboxCard::class);
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
                SwarmHealthCommand::class,
                SwarmPruneCommand::class,
                SwarmStatusCommand::class,
                SwarmHistoryCommand::class,
                SwarmPauseCommand::class,
                SwarmResumeCommand::class,
                SwarmCancelCommand::class,
                SwarmRecoverCommand::class,
                SwarmRelayCommand::class,
                SwarmAuditStatusCommand::class,
                SwarmAuditReconcileCommand::class,
                SwarmSignalCommand::class,
                SwarmInspectCommand::class,
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
