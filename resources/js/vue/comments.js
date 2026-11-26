import { defineComponent, h, onBeforeUnmount, onMounted, onScopeDispose, ref, unref } from 'vue'
import { commentsOf } from '../core/comments.js'
import { renderComments } from '../ui/comments.js'

/**
 * Comments for Vue, with `Features::comments()` on:
 *
 *     import { Comments } from '@solutionforest/laravel-collaboration/vue/comments'
 */

/**
 * A record's comments, for an app's own UI. `<Comments>` is the ready-made one.
 *
 *     const { comments, post, resolve } = useComments(form)
 *
 * @param {{collaboration: {room: any}}} form
 */
export function useComments(form) {
    const room = unref(form.collaboration.room)
    const comments = room ? commentsOf(room) : null
    const list = ref(comments?.list ?? [])
    const mentionable = ref(comments?.mentionable ?? [])
    const canWrite = ref(comments?.canWrite ?? false)

    const stop = comments?.on('change', () => {
        list.value = [...comments.list]
        mentionable.value = [...comments.mentionable]
        canWrite.value = comments.canWrite
    })

    onScopeDispose(() => stop?.())

    return {
        comments: list,
        mentionable,
        canWrite,
        post: (/** @type {string} */ body, /** @type {string | null} */ field = null) => comments?.post(body, field),
        resolve: (/** @type {number} */ id) => comments?.resolve(id),
        reopen: (/** @type {number} */ id) => comments?.reopen(id),
    }
}

/** A record's comments, with a box to write one: `<Comments :form="form" />`, or `field="title"`. */
export const Comments = defineComponent({
    name: 'CollaborationComments',
    props: { form: { type: Object, required: true }, field: { type: String, default: undefined } },
    setup(props) {
        const element = ref(/** @type {HTMLElement | null} */ (null))
        /** @type {(() => void) | undefined} */
        let remove

        onMounted(() => {
            const room = unref(props.form.collaboration.room)

            if (room && element.value) remove = renderComments(element.value, room, { field: props.field })
        })

        onBeforeUnmount(() => remove?.())

        return () => h('div', { ref: element })
    },
})
