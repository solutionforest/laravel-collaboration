// Compiled by `npm run typecheck`: the README's React code, typed as an app would write it.
import { Avatars, FieldPeople, Status, useCollaboration, useCollaborativeForm } from '@solutionforest/laravel-collaboration/react'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/react/editor'
import type { RoomConfig } from '@solutionforest/laravel-collaboration'

export function Edit({ post, collaboration }: { post: { id: number, title: string, status: string }, collaboration: RoomConfig | null }) {
    const form = useCollaborativeForm(collaboration, { title: post.title, status: post.status, body: '' })
    const live: boolean = form.collaboration.isLive('title')
    const names: string[] = form.collaboration.peopleIn('title').map((person) => person.name)

    form.transform((data) => ({ ...data, title: data.title.trim() }))

    return (
        <form onSubmit={() => form.put(`/posts/${post.id}`)} data-live={live} title={names.join()}>
            <Avatars form={form} limit={3} />
            <Status form={form} />
            <FieldPeople form={form} field="title" />
            <input {...form.field('title')} />
            <input type="radio" {...form.field('status', { value: 'draft' })} />
            <CollaborativeEditor form={form} field="body" />
        </form>
    )
}

export function Presence({ config }: { config: RoomConfig }) {
    const collaboration = useCollaboration(config, { prop: 'room' })

    return <Avatars collaboration={collaboration} />
}
