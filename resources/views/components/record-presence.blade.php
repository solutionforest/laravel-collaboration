@props(['presence'])

{{--
    Who is on each record of a list. Wrap the list in it, and mark each row's spot:

    <x-collaboration::record-presence :presence="Collaboration::presence($posts)">
        @foreach ($posts as $post)
            <tr>… <x-collaboration::record-people :record="$post->id" /> …</tr>
        @endforeach
    </x-collaboration::record-presence>
--}}
@php($config = $presence->toArray())

<div {{ $attributes }} @if ($config !== []) data-collab-records="{{ json_encode($config) }}" @endif>
    {{ $slot }}
</div>

@assets
    <script src="{{ route('collaboration.livewire', ['v' => \SolutionForest\Collaboration\Http\Controllers\ScriptController::version()]) }}" defer></script>
@endassets
