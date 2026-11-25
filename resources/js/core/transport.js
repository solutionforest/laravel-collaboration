export class SyncError extends Error {
    /**
     * @param {number} status
     * @param {string} code the server's `error`, such as `expired`
     * @param {Record<string, any> | null} body the whole answer
     */
    constructor(status, code, body = null) {
        super(`${status} ${code}`)
        this.status = status
        this.code = code
        this.body = body
    }
}

/**
 * Sync over HTTP. It needs nothing but Laravel: no WebSocket server, no Node.
 */
export class HttpTransport {
    name = 'HTTP'

    /**
     * @param {{url: string, csrf: string}} config
     * @param {Record<string, unknown>} payload
     * @param {{keepalive?: boolean}} options
     * @returns {Promise<any>} the server's answer
     */
    async send({ url, csrf }, payload, { keepalive = false } = {}) {
        const controller = new AbortController()
        const timeout = setTimeout(() => controller.abort(), 15000)

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive,
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    // So the push for this change skips the tab that made it.
                    ...(window.Echo?.socketId?.() ? { 'X-Socket-ID': window.Echo.socketId() } : {}),
                },
                body: JSON.stringify(payload),
            })

            const body = await response.json().catch(() => null)

            if (!response.ok || !body) throw new SyncError(response.status, body?.error ?? 'failed', body)

            return body
        } finally {
            clearTimeout(timeout)
        }
    }
}
