<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Swarm Memory"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.circle-stack />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.10s="">
        @if ($snapshot['scopes']->isEmpty() && $snapshot['snapshots']->count === 0)
            <x-pulse::no-results />
        @else
            <div class="grid grid-cols-3 gap-3 p-4">
                <div class="rounded-md p-3 bg-gray-50 dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Snapshots persisted</p>
                    <p class="mt-1 text-2xl font-bold text-gray-700 dark:text-gray-200">
                        {{ number_format($snapshot['snapshots']->count) }}
                    </p>
                </div>
                <div class="rounded-md p-3 bg-gray-50 dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg snapshot bytes</p>
                    <p class="mt-1 text-2xl font-bold text-gray-700 dark:text-gray-200">
                        {{ number_format($snapshot['snapshots']->averageBytes) }}
                    </p>
                </div>
                <div class="rounded-md p-3 bg-gray-50 dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg snapshot entries</p>
                    <p class="mt-1 text-2xl font-bold text-gray-700 dark:text-gray-200">
                        {{ number_format($snapshot['snapshots']->averageEntries) }}
                    </p>
                </div>
            </div>

            @if ($snapshot['scopes']->isNotEmpty())
                <x-pulse::table>
                    <colgroup>
                        <col width="100%" />
                        <col width="0%" />
                        <col width="0%" />
                        <col width="0%" />
                        <col width="0%" />
                    </colgroup>
                    <x-pulse::thead>
                        <tr>
                            <x-pulse::th>Scope</x-pulse::th>
                            <x-pulse::th class="text-right">Entries</x-pulse::th>
                            <x-pulse::th class="text-right">Avg bytes</x-pulse::th>
                            <x-pulse::th class="text-right">Reads</x-pulse::th>
                            <x-pulse::th class="text-right">Hit rate</x-pulse::th>
                        </tr>
                    </x-pulse::thead>
                    <tbody>
                        @foreach ($snapshot['scopes'] as $row)
                            <tr wire:key="{{ $row->scope }}-spacer" class="h-2 first:h-0"></tr>
                            <tr wire:key="{{ $row->scope }}-row">
                                <x-pulse::td class="text-gray-700 dark:text-gray-300">
                                    <strong>{{ $row->scopeLabel }}</strong>
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ number_format($row->entries) }}
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ number_format($row->averageBytes) }}
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ number_format($row->readTotal) }}
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ $row->hitRate === null ? 'n/a' : $row->hitRate.'%' }}
                                </x-pulse::td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-pulse::table>
            @endif

            <div class="p-4 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800">
                Tune <code>swarm.pulse.memory.sample_rate</code> to reduce ingest cost. Run <code>php artisan swarm:memory:purge</code> on a schedule and review <code>swarm.capture.*</code> if growth is unexpected.
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
