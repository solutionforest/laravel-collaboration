import { characters, isAfter } from './id.js'

/**
 * Replicated growable array, stored as runs. Mirrors `src/Crdt/Sequence.php`.
 */
class Run {
    /**
     * @param {Id} id
     * @param {string | string[] | object} value text, kept as characters, or one item
     * @param {boolean} deleted
     * @param {Map<string, [string | null, number, number]>} attributes each formatting key's value and the id that set it
     */
    constructor(id, value, deleted = false, attributes = new Map()) {
        this.id = id
        /** @type {string[] | object} */
        this.value = typeof value === 'string' ? characters(value) : value
        this.deleted = deleted
        this.attributes = attributes
    }

    /** @returns {string[] | null} */
    get characters() {
        return Array.isArray(this.value) ? this.value : null
    }

    get isText() {
        return Array.isArray(this.value)
    }

    get length() {
        return this.characters?.length ?? 1
    }

    /** @param {Id} id */
    contains([clock, client]) {
        return client === this.id[1] && clock >= this.id[0] && clock < this.id[0] + this.length
    }

    /** @param {number} offset */
    split(offset) {
        const tail = new Run([this.id[0] + offset, this.id[1]], '', this.deleted, new Map(this.attributes))

        tail.value = /** @type {string[]} */ (this.value).splice(offset)

        return tail
    }

    /** @param {Run} next */
    join(next) {
        const own = this.characters
        const following = next.characters

        if (
            !own ||
            !following ||
            next.id[1] !== this.id[1] ||
            next.id[0] !== this.id[0] + this.length ||
            next.deleted !== this.deleted ||
            next.attributes.size !== this.attributes.size
        ) {
            return false
        }

        for (const [key, write] of this.attributes) {
            const other = next.attributes.get(key)

            if (!other || other.some((part, index) => part !== write[index])) return false
        }

        for (const character of following) own.push(character)

        return true
    }

    /** @returns {Formatting} */
    formatting() {
        return Object.fromEntries(
            [...this.attributes]
                .filter(([, [value]]) => value !== null)
                .map(([key, [value]]) => [key, /** @type {string} */ (value)])
                .sort(([a], [b]) => (a < b ? -1 : 1)),
        )
    }
}

export class Sequence {
    /** @param {SequenceData} data */
    constructor(data = []) {
        /** @type {Run[]} */
        this.runs = data.map(
            ([clock, client, value, deleted, attributes]) =>
                new Run([clock, client], value, deleted === 1, new Map(Object.entries(attributes ?? {}))),
        )
        /** @type {Required<Token>[] | null} */
        this.cache = null
    }

    /**
     * @param {Id} id
     * @param {Id | null | undefined} after
     * @param {TokenValue} value
     * @param {Formatting} formatting
     */
    insert(id, after, value, formatting = {}) {
        if (this.find(id) !== -1) return

        let position = 0

        if (after) {
            const found = this.find(after)

            if (found === -1) throw new Error('The insertion position does not exist.')

            const run = this.runs[found]
            const offset = after[0] - run.id[0] + 1

            if (offset < run.length) this.runs.splice(found + 1, 0, run.split(offset))

            position = found + 1
        }

        while (position < this.runs.length && isAfter(this.runs[position].id, id)) position++

        /** @type {Map<string, [string | null, number, number]>} */
        const attributes = new Map(Object.entries(formatting).map(([key, item]) => [key, [item, id[0], id[1]]]))

        this.runs.splice(position, 0, new Run(id, value, false, attributes))
        this.compact()
    }

    /** @param {IdRange} range */
    delete(range) {
        this.each(range, (run) => (run.deleted = true))
    }

    /**
     * @param {IdRange} range
     * @param {string} key
     * @param {string | null} value
     * @param {Id} at
     */
    format(range, key, value, at) {
        this.each(range, (run) => {
            const current = run.attributes.get(key)

            if (!current || isAfter(at, [current[1], current[2]])) run.attributes.set(key, [value, at[0], at[1]])
        })
    }

    /** @returns {Required<Token>[]} */
    tokens() {
        if (this.cache) return this.cache

        /** @type {Required<Token>[]} */
        const tokens = []

        for (const run of this.runs) {
            if (run.deleted) continue

            const formatting = run.formatting()
            const values = run.characters ?? [/** @type {TokenValue} */ (run.value)]

            values.forEach((value, offset) =>
                tokens.push({ value, formatting, id: [run.id[0] + offset, run.id[1]] }),
            )
        }

        return (this.cache = tokens)
    }

    text() {
        return this.runs
            .map((run) => (run.deleted ? '' : (run.characters?.join('') ?? '')))
            .join('')
    }

    /**
     * The number of visible items up to and including `id`. A cursor stored as
     * the id on its left stays in place however much changes around it.
     *
     * @param {Id | null | undefined} id
     * @returns {number | null} null if the id is not in this sequence
     */
    indexAfter(id) {
        if (!id) return 0

        let index = 0

        for (const run of this.runs) {
            if (run.contains(id)) return run.deleted ? index : index + id[0] - run.id[0] + 1
            if (!run.deleted) index += run.length
        }

        return null
    }

    /**
     * @param {number} index
     * @returns {Id | null}
     */
    idBefore(index) {
        let remaining = index

        for (const run of this.runs) {
            if (run.deleted) continue
            if (remaining > 0 && remaining <= run.length) return [run.id[0] + remaining - 1, run.id[1]]

            remaining -= run.length
        }

        return null
    }

    /** @param {Id} id */
    find(id) {
        return this.runs.findIndex((run) => run.contains(id))
    }

    /**
     * @param {IdRange} range
     * @param {(run: Run) => void} callback
     */
    each([clock, client, length], callback) {
        const end = clock + length
        /** @type {Run[]} */
        const runs = []

        for (let run of this.runs) {
            const start = run.id[0]
            const finish = start + run.length

            if (run.id[1] !== client || start >= end || finish <= clock) {
                runs.push(run)
                continue
            }

            const tail = end < finish ? run.split(end - start) : null

            if (clock > start) {
                runs.push(run)
                run = run.split(clock - start)
            }

            callback(run)
            runs.push(run)

            if (tail) runs.push(tail)
        }

        this.runs = runs
        this.compact()
    }

    compact() {
        /** @type {Run[]} */
        const runs = []

        for (const run of this.runs) {
            if (!runs.at(-1)?.join(run)) runs.push(run)
        }

        this.runs = runs
        this.cache = null
    }
}
