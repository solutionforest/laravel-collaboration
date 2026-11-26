import { commentsOf } from '../core/comments.js'
import { translate } from './avatars.js'
import { addStyles } from './styles.js'

/**
 * A record's comments, drawn into an element: the list, resolved ones
 * folded away, and a box to write one. Mention people as `@Name`.
 *
 * @param {HTMLElement} element
 * @param {import('../core/room.js').Room} room
 * @param {{field?: string | null}} [options] only the comments on one field
 * @returns {() => void} what removes it
 */
export function renderComments(element, room, { field } = {}) {
    addStyles()

    const comments = commentsOf(room)
    const strings = room.config.strings
    const box = document.createElement('div')

    box.className = 'collab-comments'
    element.replaceChildren(box)

    const draw = () => {
        const list = field === undefined ? comments.list : comments.about(field ?? null)
        const open = list.filter((comment) => !comment.resolved)
        const resolved = list.filter((comment) => comment.resolved)

        box.replaceChildren(
            ...(open.length ? open.map(item) : [paragraph(strings.no_comments ?? 'No comments yet.', 'collab-comments-empty')]),
            ...(resolved.length ? [folded(resolved)] : []),
            ...(comments.canWrite ? [composer()] : []),
        )
    }

    /** @param {import('../core/comments.js').RecordComment} comment */
    const item = (comment) => {
        const article = document.createElement('article')
        const meta = paragraph(`${comment.author} · ${new Date(comment.at).toLocaleString()}${comment.field && field === undefined ? ` · ${room.label(comment.field)}` : ''}`, 'collab-comment-meta')
        const body = document.createElement('div')

        article.className = 'collab-comment'
        article.toggleAttribute('data-resolved', comment.resolved)

        // Escaped by the server, mentions marked: safe to show as HTML.
        body.innerHTML = comment.html
        article.append(meta, body)

        if (comment.resolved) article.append(paragraph(translate(strings.resolved_by ?? 'Resolved by :name', { name: comment.resolvedBy ?? '' }), 'collab-comment-meta'))

        if (comments.canWrite) {
            article.append(button(comment.resolved ? (strings.reopen ?? 'Reopen') : (strings.resolve ?? 'Resolve'), () => (comment.resolved ? comments.reopen(comment.id) : comments.resolve(comment.id))))
        }

        return article
    }

    /** @param {import('../core/comments.js').RecordComment[]} resolved */
    const folded = (resolved) => {
        const details = document.createElement('details')
        const summary = document.createElement('summary')

        summary.textContent = translate(strings.resolved_count ?? 'Resolved (:count)', { count: String(resolved.length) })
        details.append(summary, ...resolved.map(item))

        return details
    }

    const composer = () => {
        const form = document.createElement('form')
        const text = document.createElement('textarea')
        const mentions = document.createElement('div')

        form.className = 'collab-comment-form'
        text.rows = 2
        text.placeholder = strings.comment_placeholder ?? 'Write a comment. Type @ to mention somebody.'
        mentions.className = 'collab-comment-mentions'

        // Who may be mentioned: click to add them.
        for (const name of comments.mentionable) {
            mentions.append(button(`@${name}`, () => {
                text.value = `${text.value}${text.value && !text.value.endsWith(' ') ? ' ' : ''}@${name} `
                text.focus()
            }))
        }

        form.append(text, mentions, Object.assign(button(strings.post ?? 'Comment', () => {}), { type: 'submit' }))
        form.addEventListener('submit', (event) => {
            event.preventDefault()

            if (!text.value.trim()) return

            comments.post(text.value, field ?? null).then(() => (text.value = '')).catch(() => {})
        })

        return form
    }

    const stop = comments.on('change', draw)

    draw()

    return () => {
        stop()
        box.remove()
    }
}

/** @param {string} text @param {string} className */
function paragraph(text, className) {
    return Object.assign(document.createElement('p'), { className, textContent: text })
}

/** @param {string} text @param {() => void} onClick */
function button(text, onClick) {
    const element = Object.assign(document.createElement('button'), { type: 'button', textContent: text })

    element.addEventListener('click', onClick)

    return element
}
