@props(['room', 'statePath' => null])

@php
    $config = $room->toArray();
@endphp

@if ($config !== [])
    <div
        {{ $attributes->class('collab-room') }}
        data-collaboration="{{ json_encode([...$config, 'statePath' => $statePath]) }}"
        data-status="connecting"
    >
        <span wire:ignore data-collab-presence></span>
        <span wire:ignore data-collab-status class="collab-status">{{ $config['strings']['connecting'] ?? '' }}</span>
    </div>

    @assets
        {{-- Until the script below has loaded, calls to Collaboration.whenReady() wait in this queue. --}}
        <script>window.Collaboration = window.Collaboration || { queue: [], whenReady(element, callback) { this.queue.push([element, callback]) } }</script>
        <script src="{{ route('collaboration.livewire', ['v' => \SolutionForest\Collaboration\Http\Controllers\ScriptController::version()]) }}" defer></script>
    @endassets
@endif
