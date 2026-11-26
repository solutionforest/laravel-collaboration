import { characters } from '../crdt/id.js'

/**
 * One edit: at a character index, what was removed and what was inserted.
 *
 * @typedef {{at: number, removed: string[], inserted: string[]}} Entry
 */

/**
 * @param {string[]} before
 * @param {string[]} after
 * @returns {Entry}
 */
function change(before, after, caret = after.length) {
    let oldEnd = before.length
    let newEnd = after.length

    while (oldEnd > 0 && newEnd > caret && before[oldEnd - 1] === after[newEnd - 1]) {
        oldEnd--
        newEnd--
    }

    let start = 0

    while (start < oldEnd && start < newEnd && before[start] === after[start]) start++

    while (oldEnd > start && newEnd > start && before[oldEnd - 1] === after[newEnd - 1]) {
        oldEnd--
        newEnd--
    }

    return { at: start, removed: before.slice(start, oldEnd), inserted: after.slice(start, newEnd) }
}

/**
 * Each person's own undo and redo, for plain inputs. The browser's stack is
 * lost as soon as a colleague's edit is written into the input, and Ctrl+Z
 * must undo your typing, never theirs.
 */
export class History {
    /** @type {Entry[]} */
    undoStack = []
    /** @type {Entry[]} */
    redoStack = []
    changedAt = 0

    /**
     * @param {string} before
     * @param {string} after
     * @param {number | null} caret
     */
    local(before, after, caret) {
        const entry = change(characters(before), characters(after), caret ?? undefined)
        const last = this.undoStack.at(-1)
        const now = Date.now()

        const continues =
            now - this.changedAt < 800 &&
            last &&
            !entry.removed.length &&
            !last.removed.length &&
            entry.at === last.at + last.inserted.length

        if (continues) last.inserted.push(...entry.inserted)
        else this.undoStack.push(entry)

        if (this.undoStack.length > 200) this.undoStack.shift()

        this.redoStack = []
        this.changedAt = now
    }

    /**
     * @param {string} before
     * @param {string} after
     */
    remote(before, after) {
        const { at, removed, inserted } = change(characters(before), characters(after))

        this.changedAt = 0
        this.rebase(this.undoStack, at, removed.length, inserted.length - removed.length, 'inserted', 'removed')
        this.rebase(this.redoStack, at, removed.length, inserted.length - removed.length, 'removed', 'inserted')
    }

    /**
     * Moves every entry past somebody else's edit, newest first.
     *
     * @param {Entry[]} stack
     * @param {number} position
     * @param {number} length
     * @param {number} delta
     * @param {'inserted' | 'removed'} present
     * @param {'inserted' | 'removed'} absent
     */
    rebase(stack, position, length, delta, present, absent) {
        for (let index = stack.length - 1; index >= 0; index--) {
            const entry = /** @type {Entry} */ (stack[index])
            const end = entry.at + entry[present].length

            if (position >= end) {
                position += entry[absent].length - entry[present].length
            } else if (position + length <= entry.at) {
                entry.at += delta
            } else {
                stack.splice(0, index + 1)

                return
            }
        }
    }

    /** @param {string} value */
    undo(value) {
        return this.replay(value, this.undoStack, this.redoStack, 'inserted', 'removed')
    }

    /** @param {string} value */
    redo(value) {
        return this.replay(value, this.redoStack, this.undoStack, 'removed', 'inserted')
    }

    /**
     * @param {string} value
     * @param {Entry[]} from
     * @param {Entry[]} to
     * @param {'inserted' | 'removed'} present
     * @param {'inserted' | 'removed'} absent
     * @returns {{value: string, caret: number} | null}
     */
    replay(value, from, to, present, absent) {
        const entry = from.pop()

        if (!entry) return null

        const items = characters(value)
        const found = items.slice(entry.at, entry.at + entry[present].length)

        if (found.join('') !== entry[present].join('')) {
            from.length = 0

            return null
        }

        items.splice(entry.at, entry[present].length, ...entry[absent])
        to.push(entry)
        this.changedAt = 0

        return { value: items.join(''), caret: entry.at + entry[absent].length }
    }
}
