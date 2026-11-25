import { Outbox } from './outbox.js'

/**
 * Keeps unsent edits through a reload, in this tab's session storage. The next
 * page load sends them as the editor that made them, so none can apply twice.
 */
export class Recovery {
    /** @type {Array<{token: string, epoch: number, outbox: Outbox}>} */
    entries = []

    /** @param {string | null} key */
    constructor(key) {
        this.key = key ? `collaboration:${key}` : null

        if (!this.key) return

        try {
            for (const { token, epoch, batches } of JSON.parse(sessionStorage.getItem(this.key) ?? '[]')) {
                if (typeof token !== 'string' || !Array.isArray(batches) || !batches.length) continue

                const outbox = new Outbox()

                outbox.batches = batches
                this.entries.push({ token, epoch, outbox })
            }
        } catch {
            // Storage is unavailable or unreadable: there is nothing to recover.
            this.entries = []
        }
    }

    get isEmpty() {
        return this.entries.length === 0
    }

    /** @returns {{token: string, epoch: number, batches: Batch[]}|null} */
    next() {
        const entry = this.entries[0]

        return entry ? { token: entry.token, epoch: entry.epoch, batches: entry.outbox.next() } : null
    }

    /**
     * @param {{batches: Batch[]} | null} sent
     * @param {boolean | null | undefined} resumed
     * @returns {boolean} false when those edits could not be recovered
     */
    receive(sent, resumed) {
        const entry = this.entries[0]

        if (!sent || !entry) return true

        if (resumed) entry.outbox.acknowledge(sent.batches.at(-1)?.n ?? 0)
        if (!resumed || entry.outbox.isEmpty) this.entries.shift()

        return !!resumed
    }

    /**
     * @param {string} token
     * @param {number} epoch
     * @param {Batch[]} batches
     */
    store(token, epoch, batches) {
        if (!this.key) return

        const entries = this.entries.map((entry) => ({ token: entry.token, epoch: entry.epoch, batches: entry.outbox.batches }))

        if (batches.length) entries.push({ token, epoch, batches })

        try {
            if (entries.length) sessionStorage.setItem(this.key, JSON.stringify(entries))
            else sessionStorage.removeItem(this.key)
        } catch {
            // A full or blocked storage only costs recovery; `beforeunload` still warns.
        }
    }
}
