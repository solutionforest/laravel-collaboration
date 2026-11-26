import { translate } from './avatars.js'
import { addStyles } from './styles.js'

/**
 * @typedef {{id: number, reason: string, name: string | null, at: string, changed: string[]}} Version
 */

/**
 * The record's history, drawn into an element: each version of the shared
 * draft, newest first, with what changed, and a Restore button for editors.
 *
 * @param {HTMLElement} element
 * @param {import('../core/room.js').Room} room
 * @returns {() => void} what removes it
 */
export function renderHistory(element, room) {
    addStyles()

    const strings = room.config.strings
    const list = document.createElement('ol')
    let stopped = false

    list.className = 'collab-history'
    element.replaceChildren(list)

    /** @param {{versions: Version[], canRestore: boolean}} answer */
    const draw = ({ versions, canRestore }) => {
        if (stopped) return

        list.replaceChildren(...(versions.length ? versions.map((version) => item(version, canRestore)) : [Object.assign(document.createElement('li'), { className: 'collab-comments-empty', textContent: strings.no_history ?? 'Nothing has changed yet.' })]))
    }

    /** @param {Version} version @param {boolean} canRestore */
    const item = (version, canRestore) => {
        const li = document.createElement('li')
        const time = new Date(version.at).toLocaleString()
        const what = translate(strings[`version_${version.reason}`] ?? ':name', { name: version.name ?? strings.someone ?? 'Someone' })

        li.className = 'collab-version'
        li.append(Object.assign(document.createElement('p'), { textContent: `${what} · ${time}` }))

        if (version.changed.length) li.append(Object.assign(document.createElement('p'), { className: 'collab-comment-meta', textContent: version.changed.map((field) => room.label(field)).join(', ') }))

        if (canRestore) {
            const restore = Object.assign(document.createElement('button'), { type: 'button', textContent: strings.restore_version ?? 'Restore' })

            restore.addEventListener('click', () => {
                if (window.confirm(translate(strings.restore_confirm ?? 'Restore the draft as it was at :time?', { time }))) room.call('versions.restore', { id: version.id }).then(draw).catch(() => {})
            })
            li.append(restore)
        }

        return li
    }

    const refresh = () => room.call('versions').then(draw).catch(() => {})
    const timer = setInterval(refresh, 30_000)
    const stop = room.on('saved', refresh)

    refresh()

    return () => {
        stopped = true
        clearInterval(timer)
        stop()
        list.remove()
    }
}
