import { createElement, useEffect, useMemo, useRef, useState } from 'react'
import { commentsOf } from '../core/comments.js'
import { renderComments } from '../ui/comments.js'

/**
 * Comments for React, with `Features::comments()` on:
 *
 *     import { Comments } from '@solutionforest/laravel-collaboration/react/comments'
 */

/** @typedef {import('../core/room.js').Room} Room */

/**
 * A record's comments, for an app's own UI. `<Comments>` is the ready-made one.
 *
 *     const { comments, post, resolve } = useComments(form)
 *
 * @param {{collaboration: {room: Room | null}} | {room: Room | null}} source a form, or a `useCollaboration()`
 */
export function useComments(source) {
    const room = 'collaboration' in source ? source.collaboration.room : source.room
    const [version, setVersion] = useState(0)
    const comments = room ? commentsOf(room) : null

    useEffect(() => comments?.on('change', () => setVersion((value) => value + 1)), [comments])

    return useMemo(() => ({
        comments: comments?.list ?? [],
        mentionable: comments?.mentionable ?? [],
        canWrite: comments?.canWrite ?? false,
        post: (/** @type {string} */ body, /** @type {string | null} */ field = null) => comments?.post(body, field),
        resolve: (/** @type {number} */ id) => comments?.resolve(id),
        reopen: (/** @type {number} */ id) => comments?.reopen(id),
    }), [comments, version])
}

/**
 * A record's comments, with a box to write one: `<Comments form={form} />`,
 * or `field="title"` for one field's.
 *
 * @param {{form: {collaboration: {room: Room | null}}, field?: string | null, className?: string}} props
 */
export function Comments({ form, field, className = '' }) {
    const element = useRef(/** @type {HTMLDivElement | null} */ (null))
    const { room } = form.collaboration

    useEffect(() => (room && element.current ? renderComments(element.current, room, { field }) : undefined), [room, field])

    return createElement('div', { ref: element, className })
}
