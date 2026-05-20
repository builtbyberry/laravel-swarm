<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Swarm Audit Outbox"
        details="live operational state"
    >
        <x-slot:icon>
            <x-pulse::icons.clipboard />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.10s="">
        @if (! $available)
            <div class="p-4 text-sm text-gray-500 dark:text-gray-400">
                Audit outbox unavailable on cache persistence driver.
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Switch <code>swarm.persistence.driver</code> to <code>database</code> and run the package migrations to enable it.
                </p>
            </div>
        @else
            <div class="grid grid-cols-3 gap-3 p-4">
                <div class="rounded-md p-3 {{ $snapshot->deadLetter > 0 ? 'bg-red-50 dark:bg-red-900/30 ring-1 ring-red-500/40' : 'bg-gray-50 dark:bg-gray-800' }}">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Dead-letter</p>
                    <p class="mt-1 text-2xl font-bold {{ $snapshot->deadLetter > 0 ? 'text-red-700 dark:text-red-300' : 'text-gray-700 dark:text-gray-200' }}">
                        {{ number_format($snapshot->deadLetter) }}
                    </p>
                    @if ($snapshot->deadLetter > 0)
                        <span class="inline-flex items-center gap-1 mt-1 rounded-full bg-red-100 dark:bg-red-900/60 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-200">
                            attention
                        </span>
                    @endif
                </div>

                <div class="rounded-md p-3 bg-gray-50 dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Pending</p>
                    <p class="mt-1 text-2xl font-bold text-gray-700 dark:text-gray-200">
                        {{ number_format($snapshot->pending) }}
                    </p>
                </div>

                <div class="rounded-md p-3 {{ $snapshot->stalePending > 0 ? 'bg-amber-50 dark:bg-amber-900/30 ring-1 ring-amber-500/40' : 'bg-gray-50 dark:bg-gray-800' }}">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Stale pending
                        <span class="text-[10px] normal-case text-gray-400 dark:text-gray-500">
                            (&gt; {{ $snapshot->staleWindowSeconds }}s)
                        </span>
                    </p>
                    <p class="mt-1 text-2xl font-bold {{ $snapshot->stalePending > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-200' }}">
                        {{ number_format($snapshot->stalePending) }}
                    </p>
                    @if ($snapshot->stalePending > 0)
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                            relay may not be running
                        </p>
                    @endif
                </div>
            </div>

            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Metric</x-pulse::th>
                        <x-pulse::th class="text-right">Value</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    <tr>
                        <x-pulse::td class="text-gray-700 dark:text-gray-300">Oldest pending age</x-pulse::td>
                        <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                            {{ $snapshot->oldestPendingAge ?? 'n/a' }}
                        </x-pulse::td>
                    </tr>
                    <tr>
                        <x-pulse::td class="text-gray-700 dark:text-gray-300">Oldest dead-letter age</x-pulse::td>
                        <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                            {{ $snapshot->oldestDeadLetterAge ?? 'n/a' }}
                        </x-pulse::td>
                    </tr>
                    <tr>
                        <x-pulse::td class="text-gray-700 dark:text-gray-300">Dead-letter retention</x-pulse::td>
                        <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                            {{ $snapshot->retentionDays === null ? 'indefinite' : $snapshot->retentionDays.' days' }}
                        </x-pulse::td>
                    </tr>
                </tbody>
            </x-pulse::table>

            <div class="p-4 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800">
                Run <code>php artisan swarm:audit:status</code> for detail, <code>swarm:audit:reconcile</code> to triage.
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
