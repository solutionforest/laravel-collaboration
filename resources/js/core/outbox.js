const MAX_OPERATIONS_PER_BATCH = 1000
const MAX_BATCHES_PER_REQUEST = 500
const MAX_OPERATIONS_PER_REQUEST = 10_000
const MAX_CHARACTERS_PER_REQUEST = 600_000

/**
 * Edits the server has not acknowledged. A batch never changes once numbered,
 * so both sync and Save may deliver it and the server still applies it once.
 */
export class Outbox {
    /** @type {Batch[]} */
    batches = []
    last = 0

    get isEmpty() {
        return this.batches.length === 0
    }

    /** @param {Operation[]} operations */
    push(operations) {
        for (let start = 0; start < operations.length; start += MAX_OPERATIONS_PER_BATCH) {
            this.batches.push({ n: ++this.last, ops: operations.slice(start, start + MAX_OPERATIONS_PER_BATCH) })
        }
    }

    /** @returns {Batch[]} as many as one request may carry */
    next() {
        /** @type {Batch[]} */
        const batches = []
        let size = 0
        let operations = 0

        for (const batch of this.batches.slice(0, MAX_BATCHES_PER_REQUEST)) {
            size += JSON.stringify(batch).length
            operations += batch.ops.length

            if (batches.length && (size > MAX_CHARACTERS_PER_REQUEST || operations > MAX_OPERATIONS_PER_REQUEST)) break

            batches.push(batch)
        }

        return batches
    }

    /** @param {number} number the last batch the server applied */
    acknowledge(number) {
        this.batches = this.batches.filter((batch) => batch.n > number)
        this.last = Math.max(this.last, number)
    }

    reset() {
        this.batches = []
        this.last = 0
    }
}
