import { canonical } from '../crdt/id.js'

/** @type {(a: unknown, b: unknown) => boolean} */
const same = (a, b) => canonical(a ?? null) === canonical(b ?? null)

/** @type {<T>(value: T) => T} */
const copy = (value) => (value === undefined ? value : JSON.parse(JSON.stringify(value)))

/**
 * @typedef {{field: string, mine: unknown, theirs: unknown, name: string | null}} Conflict
 */

/**
 * What a form's fields held when it loaded or was last saved: its base. Live
 * fields are merged by the room; for the others, the base tells a field this
 * person changed from one they did not, when somebody else saves.
 *
 *     const memory = new FormMemory(room, form.data)
 *     const { take, conflicts } = await memory.pull(form.data, 'Bob')
 */
export class FormMemory {
    /** @type {Conflict[]} fields both this person and the last saver changed */
    conflicts = []

    /**
     * @param {import('./room.js').Room} room
     * @param {Record<string, unknown>} values the form as it loaded
     */
    constructor(room, values) {
        this.room = room
        this.base = copy(values)
    }

    /**
     * The form was saved: what it holds now is the base.
     *
     * @param {Record<string, unknown>} values
     */
    saved(values) {
        this.base = copy(values)
        this.conflicts = []
    }

    /**
     * Somebody else saved. Their values go into the fields this person did
     * not change; a field both changed becomes a conflict to settle.
     *
     * @param {Record<string, unknown>} current what the form holds now
     * @param {string | null} name who saved
     * @returns {Promise<{take: Record<string, unknown>, conflicts: Conflict[]}>}
     */
    async pull(current, name = null) {
        const fields = Object.keys(current).filter((field) => !this.room.isLiveField(field))

        if (!fields.length || !this.room.config.endpoints?.values) return { take: {}, conflicts: this.conflicts }

        const { values } = await this.room.call('values', { fields })
        /** @type {Record<string, unknown>} */
        const take = {}

        for (const field of fields) {
            if (!(field in values)) continue

            const theirs = values[field]

            if (same(theirs, this.base[field])) continue

            if (same(current[field], this.base[field])) {
                take[field] = theirs
                this.base[field] = copy(theirs)
            } else if (!same(current[field], theirs)) {
                this.conflicts = [...this.conflicts.filter((conflict) => conflict.field !== field), { field, mine: current[field], theirs, name }]
            } else {
                this.base[field] = copy(theirs)
            }
        }

        return { take, conflicts: this.conflicts }
    }

    /**
     * Keep this person's value: saving again overwrites theirs.
     *
     * @param {string} field
     */
    keepMine(field) {
        const conflict = this.settle(field)

        if (conflict) this.base[field] = copy(conflict.theirs)
    }

    /**
     * Take the other person's value instead.
     *
     * @param {string} field
     * @returns {unknown} the value to put in the form
     */
    useTheirs(field) {
        const conflict = this.settle(field)

        if (conflict) this.base[field] = copy(conflict.theirs)

        return conflict?.theirs
    }

    /** What a save sends, so the server can tell what this person changed. */
    payload() {
        return JSON.stringify(this.base ?? {})
    }

    /** @param {string} field */
    settle(field) {
        const conflict = this.conflicts.find((item) => item.field === field)

        this.conflicts = this.conflicts.filter((item) => item.field !== field)

        return conflict
    }
}
