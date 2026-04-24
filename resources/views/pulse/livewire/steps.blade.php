<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Swarm Steps"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($steps->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Swarm</x-pulse::th>
                        <x-pulse::th>Agent</x-pulse::th>
                        <x-pulse::th>Topology</x-pulse::th>
                        <x-pulse::th class="text-right">Avg Duration</x-pulse::th>
                        <x-pulse::th class="text-right">Samples</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($steps as $step)
                        <tr wire:key="{{ $step->swarmClass }}-{{ $step->agentClass }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $step->swarmClass }}-{{ $step->agentClass }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $step->swarmClass }}">
                                    {{ class_basename($step->swarmClass) }}
                                </code>
                            </x-pulse::td>
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $step->agentClass }}">
                                    {{ class_basename($step->agentClass) }}
                                </code>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $step->agentClass }}">
                                    {{ $step->agentClass }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td class="text-gray-700 dark:text-gray-300">
                                {{ $step->topology }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ number_format($step->averageDurationMs) ?: '<1' }}</strong> ms
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($step->count) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
