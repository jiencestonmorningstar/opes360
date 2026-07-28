/**
 * The offline write path.
 *
 * Everything a form saves without a connection goes through here so there is
 * one place that knows the rule: write the local mirror and the outbox envelope
 * together, then let the sync engine deliver it. The record the user sees
 * immediately is the same record the server will eventually hold.
 */

/**
 * ULIDs, not UUIDs: the server's primary keys are ULIDs, and a record created
 * offline keeps the id it was born with all the way to the database. Sortable
 * ids also mean the outbox replays in creation order for free.
 */
export function ulid() {
    const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    let time = Date.now();
    let timeChars = '';

    for (let i = 0; i < 10; i++) {
        timeChars = ENCODING[time % 32] + timeChars;
        time = Math.floor(time / 32);
    }

    const random = crypto.getRandomValues(new Uint8Array(16));
    let randomChars = '';

    for (let i = 0; i < 16; i++) {
        randomChars += ENCODING[random[i] % 32];
    }

    return timeChars + randomChars;
}

export class OfflineWriter {
    constructor(engine) {
        this.engine = engine;
    }

    get store() {
        return this.engine.store;
    }

    /**
     * Saves a record locally and queues it for the server.
     *
     * @param  {string} type     Entity type the sync protocol knows.
     * @param  {object} payload  Column values, exactly as the server expects.
     * @param  {object} extra    Envelope extras — `lines`, `assigned_number`.
     */
    async create(type, payload, extra = {}) {
        const id = payload.id ?? ulid();
        const now = new Date().toISOString();

        const record = {
            ...payload,
            id,
            created_at: now,
            updated_at: now,
            // Marks the record as not yet acknowledged, so lists can show it as
            // pending rather than pretending it is safely on the server.
            _pending: true,
        };

        if (extra.lines) {
            record.lines = extra.lines;
        }

        await this.store.putEntity(type, record);

        await this.engine.record(type, id, 'create', payload, extra);

        return record;
    }

    async update(type, id, payload, extra = {}) {
        const existing = (await this.store.find(type, id)) ?? {};

        await this.store.putEntity(type, {
            ...existing,
            ...payload,
            id,
            updated_at: new Date().toISOString(),
            _pending: true,
        });

        await this.engine.record(type, id, 'update', payload, extra);
    }

    /** Local mirror of a list, newest first, for rendering while offline. */
    async list(type, { where = null, limit = 200 } = {}) {
        const rows = await this.store.all(type);

        return rows
            .filter((row) => (where ? where(row) : true) && ! row.deleted_at)
            .sort((a, b) => String(b.created_at ?? '').localeCompare(String(a.created_at ?? '')))
            .slice(0, limit);
    }

    find(type, id) {
        return this.store.find(type, id);
    }
}
