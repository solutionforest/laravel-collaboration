import { renderComments } from '../ui/comments.js'
import { renderHistory } from '../ui/history.js'

/**
 * The script of `<x-collaboration::comments>` and `<x-collaboration::history>`:
 * only pages that show them load it.
 */

function mount() {
    for (const element of document.querySelectorAll(/** @type {'div'} */ ('[data-collab-comments], [data-collab-history]'))) {
        if (element.dataset.collabMounted || !window.Collaboration?.whenReady) continue

        element.dataset.collabMounted = '1'

        window.Collaboration.whenReady(element, (room) => {
            if (element.hasAttribute('data-collab-history')) renderHistory(element, room)
            else renderComments(element, room, element.dataset.field ? { field: element.dataset.field } : {})
        })
    }
}

document.addEventListener('livewire:initialized', mount)
document.addEventListener('livewire:navigated', mount)
setInterval(mount, 500)
