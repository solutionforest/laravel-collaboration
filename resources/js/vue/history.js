import { defineComponent, h, onBeforeUnmount, onMounted, onScopeDispose, ref, unref } from 'vue'
import { renderHistory } from '../ui/history.js'

/**
 * Version history for Vue, with `Features::history()` on:
 *
 *     import { History } from '@solutionforest/laravel-collaboration/vue/history'
 */

/** The record's history, with Restore for editors: `<History :form="form" />`. */
export const History = defineComponent({
    name: 'CollaborationHistory',
    props: { form: { type: Object, required: true } },
    setup(props) {
        const element = ref(/** @type {HTMLElement | null} */ (null))
        /** @type {(() => void) | undefined} */
        let remove

        onMounted(() => {
            const room = unref(props.form.collaboration.room)

            if (room && element.value) remove = renderHistory(element.value, room)
        })

        onBeforeUnmount(() => remove?.())

        return () => h('div', { ref: element })
    },
})

/**
 * The record's history, for an app's own UI: `const { versions, restore } = useVersions(form)`.
 *
 * @param {{collaboration: {room: any}}} form
 */
export function useVersions(form) {
    const room = unref(form.collaboration.room)
    const versions = ref(/** @type {any[]} */ ([]))
    const canRestore = ref(false)
    const take = (/** @type {{versions: any[], canRestore: boolean}} */ answer) => {
        versions.value = answer.versions
        canRestore.value = answer.canRestore
    }

    room?.call('versions').then(take).catch(() => {})

    const stop = room?.on('saved', () => room.call('versions').then(take).catch(() => {}))

    onScopeDispose(() => stop?.())

    return { versions, canRestore, restore: (/** @type {number} */ id) => room?.call('versions.restore', { id }).then(take) }
}
