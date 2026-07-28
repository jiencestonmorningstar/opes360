import { OfflineStore } from './store.js';

/**
 * Client half of the sync protocol.
 *
 * Foreground sync is the primary path, not a fallback: iOS Safari has no
 * Background Sync API and shows no sign of getting one, so an engine that
 * depended on it would silently do nothing for a large share of users.
 * Background Sync, where available, is a bonus on top.
 * See docs/architecture/offline-sync.md §7.
 */
export class SyncEngine {
    constructor(companyId) {
        this.store = new OfflineStore(companyId);
        this.running = false;
        this.listeners = new Set();
    }

    onChange(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    async announce() {
        const pending = await this.store.pendingCount();
        const failed = (await this.store.failed())?.length ?? 0;

        this.listeners.forEach((listener) =>
            listener({ pending, failed, online: navigator.onLine, running: this.running })
        );
    }

    /** Queues a mutation locally and attempts an immediate push if online. */
    async record(entityType, entityId, operation, payload, extra = {}) {
        await this.store.enqueue({
            id: crypto.randomUUID(),
            entity_type: entityType,
            entity_id: entityId,
            operation,
            payload,
            ...extra,
        });

        await this.announce();

        if (navigator.onLine) {
            this.sync();
        }
    }

    async sync() {
        if (this.running || ! navigator.onLine) return;

        this.running = true;
        await this.announce();

        try {
            await this.push();
            await this.pull();
            await this.store.setMeta('last_synced_at', new Date().toISOString());
        } catch (e) {
            // Network failures are expected, not exceptional — leave the outbox
            // intact and try again on the next trigger.
        } finally {
            this.running = false;
            await this.announce();
        }
    }

    async push() {
        const pending = (await this.store.pending()) || [];
        if (pending.length === 0) return;

        // Ordered by creation so a document never arrives before the customer
        // it references.
        pending.sort((a, b) => a.created_at.localeCompare(b.created_at));

        const batch = pending.slice(0, 100);

        const response = await fetch('/api/sync/v1/push', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                device_id: await this.store.meta('device_id'),
                device_name: navigator.userAgent,
                envelopes: batch,
            }),
        });

        if (! response.ok) {
            throw new Error(`push failed: ${response.status}`);
        }

        const { results = [] } = await response.json();

        for (const result of results) {
            if (result.status === 'applied' || result.status === 'duplicate') {
                await this.store.markDone(result.id);
                continue;
            }

            // Rejected and conflicted envelopes are terminal: retrying cannot
            // help, and the user needs to see why.
            await this.store.markFailed(result.id, result.error ?? result.status);
        }
    }

    async pull() {
        const cursors = (await this.store.meta('cursors')) ?? {};
        const query = new URLSearchParams();

        Object.entries(cursors).forEach(([type, value]) => query.set(`since[${type}]`, value));

        const response = await fetch(`/api/sync/v1/pull?${query}`, {
            headers: { Accept: 'application/json' },
        });

        if (! response.ok) {
            throw new Error(`pull failed: ${response.status}`);
        }

        const { changes = {}, cursors: next = {} } = await response.json();

        for (const [type, records] of Object.entries(changes)) {
            if (records.length) {
                await this.store.putMany(type, records);
            }
        }

        await this.store.setMeta('cursors', next);
    }

    /**
     * Wires every trigger that can reasonably mean "we might be online now".
     * Deliberately generous: a missed sync is invisible to the user until it
     * costs them something.
     */
    start() {
        window.addEventListener('online', () => this.sync());
        window.addEventListener('focus', () => this.sync());

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.sync();
        });

        // Periodic while the tab is open, which is the only guarantee iOS gives.
        setInterval(() => this.sync(), 60_000);

        this.sync();
        this.announce();
    }
}
