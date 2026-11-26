/** The few styles the package draws with, added to the page once. Override any of them in your own CSS. */
const CSS = `
.collab-carets { position: fixed; inset: 0; pointer-events: none; z-index: 40; }
.collab-caret { position: fixed; width: 2px; border-radius: 1px; }
.collab-caret-label { position: absolute; bottom: 100%; left: -1px; padding: 1px 5px; border-radius: 4px 4px 4px 0; color: #fff; font: 600 11px/1.4 system-ui, sans-serif; white-space: nowrap; }
.collab-selection { position: fixed; opacity: .25; }
.collab-avatars { display: inline-flex; align-items: center; }
.collab-avatar { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; margin-left: -6px; border-radius: 9999px; border: 2px solid #fff; color: #fff; font: 600 11px/1 system-ui, sans-serif; overflow: hidden; }
.collab-avatar:first-child { margin-left: 0; }
.collab-avatar img { width: 100%; height: 100%; object-fit: cover; }
.collab-avatar[data-idle] { opacity: .45; }
button.collab-avatar, .collab-avatar[role=button] { padding: 0; cursor: pointer; }
.collab-avatar[data-following] { box-shadow: 0 0 0 2px #fff, 0 0 0 4px currentColor; color: #4f46e5; }
.collab-field-people { display: inline-flex; gap: 4px; }
.collab-field-person { padding: 1px 6px; border-radius: 9999px; color: #fff; font: 600 11px/1.5 system-ui, sans-serif; }
.collab-comments { display: grid; gap: 10px; font: 14px/1.5 system-ui, sans-serif; color: #0f172a; }
.collab-comment { padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
.collab-comment[data-resolved] { opacity: .6; }
.collab-comment button, .collab-comment-form button { margin-top: 6px; padding: 2px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; font: 600 13px system-ui, sans-serif; cursor: pointer; }
.collab-comment-meta, .collab-comments-empty { margin: 0 0 4px; color: #64748b; font-size: 12px; }
.collab-comment-form textarea { width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; }
.collab-comment-mentions { display: flex; flex-wrap: wrap; gap: 4px; }
.collab-mention { padding: 0 3px; border-radius: 4px; background: #e0e7ff; color: #3730a3; font-weight: 600; }
.collab-history { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; font: 14px/1.5 system-ui, sans-serif; }
.collab-version { padding: 8px 12px; border-left: 3px solid #c7d2fe; }
.collab-version p { margin: 0; }
.collab-version button { margin-top: 4px; padding: 2px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; font: 600 13px system-ui, sans-serif; cursor: pointer; }
.collab-conflicts { display: grid; gap: 8px; }
.collab-conflict { padding: 10px 12px; border: 1px solid #f59e0b; border-radius: 8px; background: #fffbeb; font: 14px/1.5 system-ui, sans-serif; color: #78350f; }
.collab-conflict p { margin: 0 0 6px; }
.collab-conflict-theirs { padding: 4px 8px; border-radius: 4px; background: #fff; white-space: pre-wrap; }
.collab-conflict button { margin-right: 6px; padding: 3px 10px; border: 1px solid #d97706; border-radius: 6px; background: #fff; color: #92400e; font: 600 13px system-ui, sans-serif; cursor: pointer; }
[data-collab-busy] { outline: 2px solid var(--collab-color); outline-offset: 1px; }
.ProseMirror .collab-pm-caret { position: relative; margin: 0 -1px; border-left: 2px solid; word-break: normal; pointer-events: none; }
.ProseMirror .collab-pm-caret > span { position: absolute; bottom: 100%; left: -2px; padding: 1px 5px; border-radius: 4px 4px 4px 0; color: #fff; font: 600 11px/1.4 system-ui, sans-serif; white-space: nowrap; user-select: none; }
`

let added = false
let enabled = true

/**
 * Leaves all the styling to the app: the elements keep their `collab-`
 * class names to style. `Collaboration::withoutStyles()` does the same from PHP.
 */
export function withoutStyles() {
    enabled = false
    document?.querySelector('style[data-collaboration]')?.remove()
}

export function addStyles() {
    if (added || !enabled || typeof document === 'undefined') return

    added = true

    const style = document.createElement('style')

    style.dataset.collaboration = ''
    style.textContent = CSS
    document.head.prepend(style)
}
