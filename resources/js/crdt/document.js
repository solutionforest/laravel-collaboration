import { canonical, characters, isAfter, wellFormed } from './id.js'
import { diff } from './diff.js'
import { ItemList } from './item-list.js'
import { toDocument, toTokens } from './rich-text.js'
import { Sequence } from './sequence.js'

/** The shared draft in the browser. Mirrors `src/Crdt/Document.php`. */

/**
 * @typedef {{kind: 'value', value: any, at: Id}
 *   | {kind: 'items', list: ItemList}
 *   | {kind: 'text' | 'rich', sequence: Sequence}} SharedField
 */

/**
 * Empty is nothing, and text is well formed, as on the PHP side.
 *
 * @param {any} value
 * @returns {any}
 */
export const normalizeValue = (value) =>
    value === '' || value === undefined
        ? null
        : typeof value === 'string'
          ? wellFormed(value)
          : Array.isArray(value)
            ? value.map(normalizeValue)
            : value

/** @type {(a: unknown, b: unknown) => boolean} */
export const sameValue = (a, b) => canonical(normalizeValue(a)) === canonical(normalizeValue(b))

export class Document {
    /** @param {DocumentState} state */
    constructor(state = {}) {
        this.clock = state.clock ?? 0
        /** @type {Map<string, SharedField>} */
        this.fields = new Map(
            Object.entries(state.fields ?? {}).map(([name, field]) => [
                name,
                field.k === 'value'
                    ? { kind: field.k, value: field.v, at: field.at ?? [0, 0] }
                    : field.k === 'items'
                      ? { kind: field.k, list: new ItemList(field, normalizeValue) }
                      : { kind: field.k, sequence: new Sequence(field.r) },
            ]),
        )
    }

    /** @param {string} name */
    kind(name) {
        return this.fields.get(name)?.kind ?? null
    }

    /**
     * A text or rich text field's characters.
     *
     * @param {string} name
     */
    sequence(name) {
        const field = this.fields.get(name)

        return field && 'sequence' in field ? field.sequence : null
    }

    /**
     * A repeater's or builder's items.
     *
     * @param {string} name
     */
    list(name) {
        const field = this.fields.get(name)

        return field?.kind === 'items' ? field.list : null
    }

    /**
     * @param {string} name
     * @returns {any} text, a rich document, items or a value
     */
    value(name) {
        const field = this.fields.get(name)

        if (!field) return null
        if (field.kind === 'value') return field.value
        if (field.kind === 'text') return field.sequence.text()
        if (field.kind === 'items') return field.list.value()

        return toDocument(field.sequence.tokens())
    }

    /**
     * The operations that turn the field into `value`.
     *
     * @param {string} name
     * @param {any} value
     * @param {number} client
     * @param {any} caret for text, the index after the edit; for items, `{key, path, index}`
     * @returns {Operation[]}
     */
    changes(name, value, client, caret = null) {
        const field = this.fields.get(name)

        if (!field) return []

        if (field.kind === 'value') {
            return sameValue(field.value, value)
                ? []
                : [{ o: 'set', f: name, id: [this.clock + 1, client], v: normalizeValue(value) }]
        }

        if (field.kind === 'items') return field.list.changes(name, value ?? {}, this.clock, client, caret)

        /** @type {Token[]} */
        let old = field.sequence.tokens()
        /** @type {Token[]} */
        let next

        if (field.kind === 'rich') {
            next = toTokens(value)

            const normal = toTokens(toDocument(old))

            if (normal.length === old.length) {
                old = old.map((token, index) => ({ ...token, formatting: normal[index]?.formatting ?? token.formatting }))
            }
        } else {
            next = characters(String(value ?? '').replace(/\r\n?/g, '\n')).map((character) => ({
                value: character,
                formatting: {},
            }))
        }

        return diff(name, old, next, this.clock, client, caret)
    }

    /**
     * @param {Operation[]} operations
     * @returns {Set<string>} the fields that were touched
     */
    apply(operations) {
        /** @type {Set<string>} */
        const touched = new Set()

        for (const operation of operations) {
            const { o, f, id, v } = operation
            const ids = operation.ids ?? []
            const field = this.fields.get(f)

            if (!field) continue

            if (field.kind === 'items') {
                const { i: key = '', p: path } = operation
                const sequence = o !== 'put' && path !== undefined ? field.list.text(key, path) : field.list.order

                if (o === 'put' && id) field.list.put(key, path ?? '', v, id)
                else if (o === 'ins' && id) sequence.insert(id, operation.after, v)
                else if (o === 'del') ids.forEach((range) => sequence.delete(range))
            } else if (field.kind === 'value') {
                if (o === 'set' && id && isAfter(id, field.at)) {
                    field.value = v
                    field.at = id
                }
            } else if (o === 'ins' && id) {
                field.sequence.insert(id, operation.after, v, operation.a)
            } else if (o === 'del') {
                ids.forEach((range) => field.sequence.delete(range))
            } else if (o === 'fmt' && id) {
                ids.forEach((range) => field.sequence.format(range, operation.k ?? '', v, id))
            }

            if (id) {
                const span = o === 'ins' && typeof v === 'string' ? characters(v).length : 1

                this.clock = Math.max(this.clock, id[0] + span - 1)
            }

            touched.add(f)
        }

        return touched
    }
}
