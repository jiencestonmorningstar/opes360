/**
 * IndexedDB store for the offline engine.
 *
 * One database per company, so switching business can never mix data — the
 * tenant boundary holds on the device exactly as it does on the server.
 * See docs/architecture/offline-sync.md.
 */

const DB_VERSION = 1;

const STORES = {
    entities: 'entities',   // local mirror, keyed "type:id"
    outbox: 'outbox',       // pending mutations, in creation order
    meta: 'meta',           // sync cursors, device id
};

export class OfflineStore {
    constructor(companyId) {
        this.companyId = companyId;
        this.name = `opes360-${companyId}`;
        this.db = null;
    }

    async open() {
        if (this.db) return this.db;

        this.db = await new Promise((resolve, reject) => {
            const request = indexedDB.open(this.name, DB_VERSION);

            request.onupgradeneeded = () => {
                const db = request.result;

                if (! db.objectStoreNames.contains(STORES.entities)) {
                    const entities = db.createObjectStore(STORES.entities, { keyPath: 'key' });
                    entities.createIndex('type', 'type');
                }

                if (! db.objectStoreNames.contains(STORES.outbox)) {
                    const outbox = db.createObjectStore(STORES.outbox, { keyPath: 'id' });
                    // Replay is ordered by creation, and status drives the UI's
                    // pending count, so both are indexed.
                    outbox.createIndex('created_at', 'created_at');
                    outbox.createIndex('status', 'status');
                }

                if (! db.objectStoreNames.contains(STORES.meta)) {
                    db.createObjectStore(STORES.meta, { keyPath: 'key' });
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        return this.db;
    }

    async tx(storeName, mode, fn) {
        const db = await this.open();

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, mode);
            const store = transaction.objectStore(storeName);
            let result;

            try {
                result = fn(store);
            } catch (e) {
                reject(e);
                return;
            }

            transaction.oncomplete = () => resolve(result?.result ?? result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    }

    // ---- entities -------------------------------------------------------

    putEntity(type, record) {
        return this.tx(STORES.entities, 'readwrite', (store) =>
            store.put({ key: `${type}:${record.id}`, type, record })
        );
    }

    async putMany(type, records) {
        return this.tx(STORES.entities, 'readwrite', (store) => {
            records.forEach((record) => store.put({ key: `${type}:${record.id}`, type, record }));
        });
    }

    async all(type) {
        const rows = await this.tx(STORES.entities, 'readonly', (store) =>
            store.index('type').getAll(type)
        );

        return (rows || []).map((row) => row.record);
    }

    async find(type, id) {
        const row = await this.tx(STORES.entities, 'readonly', (store) => store.get(`${type}:${id}`));

        return row?.record ?? null;
    }

    // ---- outbox ---------------------------------------------------------

    /**
     * Queues a mutation. The envelope id doubles as the idempotency key the
     * server deduplicates on, so a retry after an ambiguous failure is safe.
     */
    async enqueue(envelope) {
        const record = {
            status: 'pending',
            attempts: 0,
            created_at: new Date().toISOString(),
            ...envelope,
        };

        await this.tx(STORES.outbox, 'readwrite', (store) => store.put(record));

        return record;
    }

    pending() {
        return this.tx(STORES.outbox, 'readonly', (store) => store.index('status').getAll('pending'));
    }

    failed() {
        return this.tx(STORES.outbox, 'readonly', (store) => store.index('status').getAll('failed'));
    }

    async pendingCount() {
        const rows = await this.pending();

        return (rows || []).length;
    }

    async markDone(id) {
        return this.tx(STORES.outbox, 'readwrite', (store) => store.delete(id));
    }

    async markFailed(id, error) {
        const row = await this.tx(STORES.outbox, 'readonly', (store) => store.get(id));
        if (! row) return;

        return this.tx(STORES.outbox, 'readwrite', (store) =>
            store.put({ ...row, status: 'failed', error, attempts: (row.attempts || 0) + 1 })
        );
    }

    async markRetry(id) {
        const row = await this.tx(STORES.outbox, 'readonly', (store) => store.get(id));
        if (! row) return;

        const attempts = (row.attempts || 0) + 1;

        // Quarantine after ten transient failures rather than retrying forever:
        // a permanently stuck envelope is a bug to surface, not to hide.
        return this.tx(STORES.outbox, 'readwrite', (store) =>
            store.put({ ...row, attempts, status: attempts >= 10 ? 'quarantined' : 'pending' })
        );
    }

    // ---- meta -----------------------------------------------------------

    async meta(key, fallback = null) {
        const row = await this.tx(STORES.meta, 'readonly', (store) => store.get(key));

        return row?.value ?? fallback;
    }

    setMeta(key, value) {
        return this.tx(STORES.meta, 'readwrite', (store) => store.put({ key, value }));
    }
}
