import { HttpTransport } from './transport.js'

/**
 * Who is on each record of a list, from `Collaboration::presence($records)`,
 * asked again every few seconds while the page is open.
 *
 *     const presence = new RecordPresence(config)
 *     presence.on('people', () => render(presence.peopleOn(post.id)))
 */
export class RecordPresence {
    /** @type {Record<string, Person[]>} */
    people = {}
    /** @type {ReturnType<typeof setTimeout> | undefined} */
    timer
    stopped = false
    /** @type {Set<(people: Record<string, Person[]>) => void>} */
    listeners = new Set()

    /**
     * @param {{url: string, csrf: string, token: string, interval: number, people: Record<string, Person[]>}} config
     * @param {{transport?: {send: HttpTransport['send']}}} [options]
     */
    constructor(config, { transport = new HttpTransport() } = {}) {
        this.config = config
        this.transport = transport
        this.people = config.people ?? {}
        this.schedule()
    }

    /**
     * Everybody else on one record.
     *
     * @param {string | number} key the record's key
     * @returns {Person[]}
     */
    peopleOn(key) {
        return this.people[String(key)] ?? []
    }

    /** @param {(people: Record<string, Person[]>) => void} callback */
    on(_event, callback) {
        this.listeners.add(callback)

        return () => this.listeners.delete(callback)
    }

    schedule() {
        if (this.stopped) return

        const hidden = typeof document !== 'undefined' && document.hidden

        this.timer = setTimeout(() => this.refresh(), hidden ? this.config.interval * 4 : this.config.interval)
    }

    async refresh() {
        try {
            const { people } = await this.transport.send(this.config, { token: this.config.token })

            this.people = people ?? {}
            this.listeners.forEach((callback) => callback(this.people))
        } catch (error) {
            // An expired token or a page left open overnight: stop asking, keep what was shown.
            if (error?.status === 403 || error?.status === 410) return this.destroy()
        }

        this.schedule()
    }

    destroy() {
        this.stopped = true
        clearTimeout(this.timer)
        this.listeners.clear()
    }
}
