/**
 * Device-side document numbering.
 *
 * A document issued with no connection is printed and handed over immediately,
 * so it needs its final number *then* — a provisional one the server later
 * replaces would leave the customer holding paper that refers to nothing. The
 * device therefore leases a block of numbers while it still has signal and
 * consumes them offline.
 *
 * The server honours a leased number and refuses anything outside the block,
 * which is what stops a tampered client inventing its own sequence.
 * See docs/architecture/offline-sync.md §5.
 */

/** Top up when fewer than this many numbers remain in the block. */
const LOW_WATER_MARK = 5;

/** Numbers requested per top-up. Small enough that an abandoned device leaves a small gap. */
const BLOCK_SIZE = 25;

export class NumberLeases {
    constructor(store) {
        this.store = store;
    }

    key(type) {
        return `lease:${type}`;
    }

    async current(type) {
        return this.store.meta(this.key(type));
    }

    /**
     * Takes the next number for a type, or null when the device has none left.
     *
     * Returning null is a real outcome, not a failure: the caller must fall back
     * to saving a draft rather than inventing a number, because an unleased one
     * would be rejected on sync after the paper had already been handed over.
     */
    async take(type) {
        const lease = await this.current(type);

        if (! lease || lease.next_available > lease.range_end) {
            return null;
        }

        const value = lease.next_available;

        // The cursor advances *before* the number is handed out. Crashing after
        // this point burns one number; crashing after handing it out but before
        // saving would reuse it, and a duplicate invoice number is far worse
        // than a gap the lease ledger already explains.
        await this.store.setMeta(this.key(type), { ...lease, next_available: value + 1 });

        return this.format(lease, value);
    }

    format(lease, value) {
        return `${lease.prefix}-${lease.year}-${String(value).padStart(5, '0')}`;
    }

    async remaining(type) {
        const lease = await this.current(type);

        return lease ? Math.max(0, lease.range_end - lease.next_available + 1) : 0;
    }

    /** True while the device can still number this type offline. */
    async canIssueOffline(type) {
        return (await this.remaining(type)) > 0;
    }

    /**
     * Tops up every type the device might need, if online.
     *
     * Called on each successful sync: the point of a lease is to be already in
     * hand when the signal goes, so it is refreshed while things are working
     * rather than at the moment it is needed.
     */
    async refresh(types = ['invoice', 'receipt'], deviceId = null) {
        if (! navigator.onLine) return;

        for (const type of types) {
            if ((await this.remaining(type)) > LOW_WATER_MARK) continue;

            try {
                // Re-read each time: the first request of a fresh install is
                // what registers the device, and the second must use that id.
                await this.request(type, (await this.store.meta('device_id')) ?? deviceId);
            } catch (e) {
                // A failed top-up is not fatal — the device keeps whatever it
                // still holds and tries again on the next sync.
            }
        }
    }

    async request(type, deviceId = null) {
        const response = await fetch('/api/sync/v1/lease', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ type, size: BLOCK_SIZE, device_id: deviceId }),
        });

        if (! response.ok) {
            throw new Error(`lease failed: ${response.status}`);
        }

        const lease = await response.json();

        // Remember who the server thinks we are before storing the lease: the
        // block is only honoured for the device that holds it, so losing the id
        // would strand every number in it.
        if (lease.device_id) {
            await this.store.setMeta('device_id', lease.device_id);
        }

        await this.store.setMeta(this.key(type), lease);

        return lease;
    }
}
