<form wire:submit="save" class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Edit article</h1>
        </div>
        <x-collaboration::room :room="$this->collaborationRoom()" class="flex flex-row-reverse items-center gap-3 text-sm text-slate-500" />
    </div>

    <div class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <label class="block">
            <span class="field-label">Title</span>
            <input wire:model="title" class="field-input">
            @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </label>

        <label class="block">
            <span class="field-label">Summary</span>
            <textarea wire:model="summary" rows="2" class="field-input"></textarea>
        </label>

        <div class="grid grid-cols-2 gap-5">
            <label class="block">
                <span class="field-label">Status</span>
                <select wire:model="status" class="field-input">
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="mt-7 flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" wire:model="featured" class="size-4 rounded border-slate-300">
                Featured on the home page
            </label>
        </div>

        <div>
            <span class="field-label">Body</span>
            <div wire:ignore x-data="richEditor('body')" class="editor"><div x-ref="editor"></div></div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="button">Save</button>
        @if ($saved) <span class="text-sm text-emerald-600" wire:key="saved-{{ now()->timestamp }}">Saved</span> @endif
    </div>
</form>
