// Compiled by `npm run typecheck`: the README's Vue code, typed as an app would write it.
import { useCollaboration, useCollaborativeForm, vCollaborate } from '@solutionforest/laravel-collaboration/vue'
import { Collaborative } from '@solutionforest/laravel-collaboration/tiptap'
import { Room, bindTextField, type RoomConfig } from '@solutionforest/laravel-collaboration'

declare const config: RoomConfig

const form = useCollaborativeForm(() => config, { title: '', status: 'draft' })
const title: string = form.title
const reference = form.field('title')
// @ts-expect-error a misspelt field is a type error
form.field('titel')
const names: string[] = form.collaboration.people.map((person) => person.name)
const collaboration = useCollaboration(() => config)
const room: Room | null = collaboration.room.value

const plain = new Room(config)
const stop: () => void = plain.on('change', ({ field, value }) => console.log(field, value))
const unbind = bindTextField(plain, 'title', document.createElement('input'))
const extension = Collaborative.configure({ room: plain, field: 'body' })

export { reference, title, names, room, stop, unbind, extension, vCollaborate }
