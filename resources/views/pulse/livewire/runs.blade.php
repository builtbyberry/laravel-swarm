<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Swarm Runs"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.rocket-launch />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($runs->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Swarm</x-pulse::th>
                        <x-pulse::th class="text-right">Runs</x-pulse::th>
                        <x-pulse::th class="text-right">Failures</x-pulse::th>
                        <x-pulse::th class="text-right">Failure Rate</x-pulse::th>
                        <x-pulse::th class="text-right">Avg Duration</x-pulse::th>
                        <x-pulse::th>Topology Mix</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr wire:key="{{ $run->swarmClass }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $run->swarmClass }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $run->swarmClass }}">
                                    {{ class_basename($run->swarmClass) }}
                                </code>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $run->swarmClass }}">
                                    {{ $run->swarmClass }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($run->totalRuns) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($run->failures) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($run->failureRate, 1) }}%
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ number_format($run->averageRunDurationMs) ?: '<1' }}</strong> ms
                            </x-pulse::td>
                            <x-pulse::td>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($run->topologyMix as $topology)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            {{ $topology->topology }}
                                            <strong>{{ number_format($topology->count) }}</strong>
                                        </span>
                                    @endforeach
                                </div>
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
