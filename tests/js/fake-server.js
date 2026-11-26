import { Document } from '../../resources/js/crdt/document.js'
import { SyncError } from '../../resources/js/core/transport.js'

/**
 * The sync endpoint in memory: enough of `SyncController` for the client's
 * tests. Edits are kept in a log; each tab is answered with what it is missing.
 */
export class FakeServer {
    /** @type {Array<{client: number, ops: any[]}>} */
    log = []
    /** @type {Map<number, number>} */
    batches = new Map()
    /** @type {Map<number, {client: number, field: string | null, cursor: any, name: string}>} */
    people = new Map()
    /** @type {{status: number, code: string} | null} */
    failWith = null

    /** @param {Record<string, any>} state */
    constructor(state) {
        this.state = state
        this.document = new Document(state)
    }

    /** The database's value, as a reconcile writes it into the draft. */
    seed(field, value) {
        const ops = this.document.changes(field, value, 0)

        this.document.apply(ops)
        this.log.push({ client: 0, ops })
    }

    /** @param {number} client @param {string} name */
    config(client, name = `Person ${client}`) {
        return {
            id: `room-${client}`,
            url: '/sync',
            csrf: 'csrf',
            token: `token-${client}`,
            client,
            name,
            mode: 'editing',
            fields: Object.fromEntries(Object.entries(this.state.fields).map(([field, value]) => [field, value.k])),
            labels: {},
            locks: this.locks ?? [],
            draft: 'draft',
            input: '_collaboration',
            interval: 50,
            channel: null,
            avatarLimit: 5,
            reportErrors: false,
            user: `user-${client}`,
            strings: {},
        }
    }

    transport(name) {
        return {
            send: async (config, request) => {
                await Promise.resolve()

                if (this.failWith) throw new SyncError(this.failWith.status, this.failWith.code)

                return this.sync(config, request, name)
            },
        }
    }

    sync(config, request, name) {
        const { client } = config

        for (const batch of request.batches ?? []) {
            if (batch.n <= (this.batches.get(client) ?? 0)) continue

            this.document.apply(batch.ops)
            this.log.push({ client, ops: batch.ops })
            this.batches.set(client, batch.n)
        }

        if (request.leave) {
            this.people.delete(client)

            return { left: true }
        }

        this.people.set(client, { client, field: request.field, cursor: request.cursor, name })

        const isFirst = request.since < 0
        const missing = this.log.slice(Math.max(0, request.since)).filter((entry) => entry.client !== client)

        return {
            resumed: null,
            epoch: 1,
            sequence: isFirst ? 0 : this.log.length,
            // A new tab starts from the seed; its next poll brings everything since.
            state: isFirst ? this.state : null,
            acknowledged: this.batches.get(client) ?? 0,
            operations: isFirst ? [] : missing.flatMap((entry) => entry.ops),
            dirty: [],
            revision: 'r1',
            saved: { version: 1, name: null, own: false },
            presence: null,
            people: [...this.people.values()].filter((person) => person.client !== client).map((person) => ({
                client: person.client, key: `user-${person.client}`, name: person.name, initials: person.name[0], avatar: null,
                color: '#2563eb', mode: 'editing', field: person.field, cursor: person.cursor, idle: false, self: false,
            })),
            total: this.people.size - 1,
            relations: null,
        }
    }
}
