/**
 * The framework-free client. Most apps use an adapter instead:
 * `/livewire`, `/react`, `/vue` and `/tiptap`.
 */
export { Room } from './core/room.js'
export { FormMemory } from './core/form-memory.js'
export { HttpTransport, SyncError } from './core/transport.js'
export { bindTextField } from './bindings/text-field.js'
export { bindValueField } from './bindings/value-field.js'
export { isTextInput, lockWhileEditing } from './bindings/lock.js'
export { renderAvatars, describe } from './ui/avatars.js'
export { avatarsOf, peopleIn, valueOf } from './ui/people.js'
