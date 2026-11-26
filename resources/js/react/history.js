import { createElement, useEffect, useRef, useState } from 'react'
import { renderHistory } from '../ui/history.js'

/**
 * Version history for React, with `Features::history()` on:
 *
 *     import { History } from '@solutionforest/laravel-collaboration/react/history'
 */

/** @typedef {import('../core/room.js').Room} Room */

/**
 * The record's history, with Restore for editors: `<History form={form} />`.
 *
 * @param {{form: {collaboration: {room: Room | null}}, className?: string}} props
 */
export function History({ form, className = '' }) {
    const element = useRef(/** @type {HTMLDivElement | null} */ (null))
    const { room } = form.collaboration

    useEffect(() => (room && element.current ? renderHistory(element.current, room) : undefined), [room])

    return createElement('div', { ref: element, className })
}

/**
 * The record's history, for an app's own UI.
 *
 *     const { versions, restore } = useVersions(form)
 *
 * @param {{collaboration: {room: Room | null}}} form
 */
export function useVersions(form) {
    const { room } = form.collaboration
    const [state, setState] = useState(/** @type {{versions: any[], canRestore: boolean}} */ ({ versions: [], canRestore: false }))

    useEffect(() => {
        if (!room) return

        const refresh = () => room.call('versions').then(setState).catch(() => {})

        refresh()

        return room.on('saved', refresh)
    }, [room])

    return {
        ...state,
        restore: (/** @type {number} */ id) => room?.call('versions.restore', { id }).then(setState),
    }
}
