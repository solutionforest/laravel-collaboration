@props(['record'])

<span {{ $attributes->class('collab-avatars') }} wire:ignore data-collab-record="{{ $record }}"></span>
