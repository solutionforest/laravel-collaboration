import { Avatars, FieldPeople, Status, useCollaborativeForm } from '@solutionforest/laravel-collaboration/react'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/react/editor'

export default function Edit({ post, statuses, channels, collaboration }) {
    const form = useCollaborativeForm(collaboration, {
        title: post.title,
        summary: post.summary ?? '',
        body: post.body ?? '',
        status: post.status,
        featured: post.featured,
        channels: post.channels ?? [],
    })

    const save = (event) => {
        event.preventDefault()
        form.put(`/react/posts/${post.id}`, { preserveScroll: true })
    }

    return (
        <form onSubmit={save} className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Edit article</h1>
                    <Status form={form} className="text-sm text-slate-500" />
                </div>
                <Avatars form={form} />
            </div>

            <div className="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <label className="block">
                    <span className="field-label">Title <FieldPeople form={form} field="title" /></span>
                    <input className="field-input" {...form.field('title')} />
                    {form.errors.title && <p className="mt-1 text-sm text-red-600">{form.errors.title}</p>}
                </label>

                <label className="block">
                    <span className="field-label">Summary <FieldPeople form={form} field="summary" /></span>
                    <textarea rows={2} className="field-input" {...form.field('summary')} />
                </label>

                <div className="grid grid-cols-2 gap-5">
                    <label className="block">
                        <span className="field-label">Status <FieldPeople form={form} field="status" /></span>
                        <select className="field-input" {...form.field('status')}>
                            {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>

                    <label className="mt-7 flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" className="size-4 rounded border-slate-300" {...form.field('featured')} />
                        Featured on the home page
                    </label>
                </div>

                <fieldset>
                    <legend className="field-label">Publish to <FieldPeople form={form} field="channels" /></legend>
                    <div className="mt-1.5 flex gap-5">
                        {Object.entries(channels).map(([value, label]) => (
                            <label key={value} className="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" className="size-4 rounded border-slate-300" {...form.field('channels', { value })} /> {label}
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div>
                    <span className="field-label">Body <FieldPeople form={form} field="body" /></span>
                    <CollaborativeEditor form={form} field="body" className="editor" />
                </div>
            </div>

            <div className="flex items-center gap-3">
                <button type="submit" className="button" disabled={form.processing}>Save</button>
                {form.recentlySuccessful && <span className="text-sm text-emerald-600">Saved</span>}
            </div>
        </form>
    )
}
