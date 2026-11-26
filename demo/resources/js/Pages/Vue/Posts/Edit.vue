<script setup>
import { Avatars, FieldPeople, Status, useCollaborativeForm, vCollaborate } from '@solutionforest/laravel-collaboration/vue'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/vue/editor'

const props = defineProps({ post: Object, statuses: Object, channels: Object, collaboration: Object })

const form = useCollaborativeForm(() => props.collaboration, {
    title: props.post.title,
    summary: props.post.summary ?? '',
    body: props.post.body ?? '',
    status: props.post.status,
    featured: props.post.featured,
    channels: props.post.channels ?? [],
})

const save = () => form.put(`/vue/posts/${props.post.id}`, { preserveScroll: true })
</script>

<template>
    <form class="space-y-6" @submit.prevent="save">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Edit article</h1>
                <Status :form="form" class="text-sm text-slate-500" />
            </div>
            <Avatars :form="form" />
        </div>

        <div class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <label class="block">
                <span class="field-label">Title <FieldPeople :form="form" field="title" /></span>
                <input v-model="form.title" v-collaborate="form.field('title')" class="field-input">
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
            </label>

            <label class="block">
                <span class="field-label">Summary <FieldPeople :form="form" field="summary" /></span>
                <textarea v-model="form.summary" v-collaborate="form.field('summary')" rows="2" class="field-input" />
            </label>

            <div class="grid grid-cols-2 gap-5">
                <label class="block">
                    <span class="field-label">Status <FieldPeople :form="form" field="status" /></span>
                    <select v-model="form.status" v-collaborate="form.field('status')" class="field-input">
                        <option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>

                <label class="mt-7 flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input v-model="form.featured" v-collaborate="form.field('featured')" type="checkbox" class="size-4 rounded border-slate-300">
                    Featured on the home page
                </label>
            </div>

            <fieldset>
                <legend class="field-label">Publish to <FieldPeople :form="form" field="channels" /></legend>
                <div class="mt-1.5 flex gap-5">
                    <label v-for="(label, value) in channels" :key="value" class="flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="form.channels" v-collaborate="form.field('channels')" type="checkbox" :value="value" class="size-4 rounded border-slate-300"> {{ label }}
                    </label>
                </div>
            </fieldset>

            <div>
                <span class="field-label">Body <FieldPeople :form="form" field="body" /></span>
                <CollaborativeEditor :form="form" field="body" class="editor" />
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="button" :disabled="form.processing">Save</button>
            <span v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Saved</span>
        </div>
    </form>
</template>
