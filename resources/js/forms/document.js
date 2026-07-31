import { money } from '../support/money.js';

/**
 * The document composer's client state.
 *
 * Everything the form does — adding lines, computing totals, picking a customer
 * — happens with no server round trip, so the form behaves identically at four
 * bars and at none. The only network calls are the customer search (which falls
 * back to the device's own mirror) and the save itself.
 */
export default function documentForm(config) {
    return {
        currency: config.currency,
        docLabel: config.docLabel,
        entityType: config.entityType,
        canIssueOffline: config.canIssueOffline,

        contactId: null,
        contact: null,
        contactSearch: '',
        contactResults: [],
        searching: false,

        lines: [],
        issueDate: config.today,
        dueDate: config.defaultDue,
        notes: '',

        errors: {},
        saving: false,
        online: navigator.onLine,
        numbersLeft: 0,
        savedOffline: null,

        init() {
            this.lines = [this.blankLine()];

            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));

            window.opesSync?.onChange((state) => {
                this.online = state.online;
                this.numbersLeft = state.numbersLeft ?? 0;
            });
            window.opesSync?.announce();
        },

        blankLine() {
            return { description: '', quantity: '1', unit_price: '' };
        },

        addLine() {
            this.lines.push(this.blankLine());
        },

        removeLine(index) {
            // The form always keeps at least one row; "remove the last row"
            // means "clear it", which is what a user poking at a phone intends.
            if (this.lines.length === 1) {
                this.lines = [this.blankLine()];

                return;
            }

            this.lines.splice(index, 1);
        },

        lineTotal(line) {
            return (parseFloat(line.quantity) || 0) * (parseFloat(line.unit_price) || 0);
        },

        get total() {
            return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0);
        },

        format(amount) {
            return money(amount, this.currency);
        },

        /**
         * Dates for the preview, written the way the printed sheet writes them
         * ("Jul 31, 2026") rather than the input's yyyy-mm-dd.
         */
        formatDate(value) {
            if (! value) return '';

            const date = new Date(`${value}T00:00:00`);

            return isNaN(date)
                ? value
                : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },

        // ---- customer -----------------------------------------------------

        async search() {
            const term = this.contactSearch.trim();

            if (term.length < 1) {
                this.contactResults = [];

                return;
            }

            this.searching = true;

            try {
                this.contactResults = this.online
                    ? await this.$wire.searchContacts(term)
                    : await this.searchLocally(term);
            } catch (e) {
                // A search that fails mid-flight (the connection dropped between
                // the check and the call) falls back rather than showing nothing.
                this.contactResults = await this.searchLocally(term);
            } finally {
                this.searching = false;
            }
        },

        async searchLocally(term) {
            const rows = (await window.opesSync?.writer.list('contact', { limit: 1000 })) ?? [];
            const needle = term.toLowerCase();

            return rows
                .filter((row) => ['name', 'company_name', 'email'].some(
                    (field) => String(row[field] ?? '').toLowerCase().includes(needle)
                ))
                .slice(0, 6)
                .map((row) => ({
                    id: row.id,
                    name: row.company_name || row.name,
                    subtitle: row.email ?? '',
                    initials: (row.name ?? '?').trim().slice(0, 2).toUpperCase(),
                    accent: 'blue',
                }));
        },

        selectContact(result) {
            this.contactId = result.id;
            this.contact = result;
            this.contactSearch = '';
            this.contactResults = [];
            delete this.errors.contact_id;
        },

        clearContact() {
            this.contactId = null;
            this.contact = null;
        },

        // ---- saving -------------------------------------------------------

        payload() {
            return {
                contact_id: this.contactId,
                issue_date: this.issueDate,
                due_date: this.dueDate || null,
                notes: this.notes,
                lines: this.lines.map((line) => ({
                    description: line.description,
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                })),
            };
        },

        error(key) {
            const value = this.errors[key];

            return Array.isArray(value) ? value[0] : value;
        },

        lineError(index, field) {
            return this.error(`lines.${index}.${field}`);
        },

        /** Mirrors the server rules, so an offline save fails for the same reasons. */
        validate(issue) {
            const errors = {};

            if (! this.contactId) {
                errors.contact_id = ['Choose a customer first.'];
            }

            if (! this.issueDate) {
                errors.issue_date = ['An issue date is required.'];
            }

            if (this.dueDate && this.issueDate && this.dueDate < this.issueDate) {
                errors.due_date = ['The due date cannot be before the issue date.'];
            }

            this.lines.forEach((line, index) => {
                if (! String(line.description).trim()) {
                    errors[`lines.${index}.description`] = ['Every item needs a description.'];
                }

                if (! (parseFloat(line.quantity) > 0)) {
                    errors[`lines.${index}.quantity`] = ['Quantity must be more than zero.'];
                }

                if (! (parseFloat(line.unit_price) >= 0)) {
                    errors[`lines.${index}.unit_price`] = ['Price cannot be negative.'];
                }
            });

            if (issue && ! this.canIssueOffline) {
                errors.form = [`A ${this.docLabel.toLowerCase()} can only be issued online. Save it as a draft for now.`];
            }

            this.errors = errors;

            return Object.keys(errors).length === 0;
        },

        async saveDraft() {
            await this.persist(false);
        },

        async saveAndIssue() {
            await this.persist(true);
        },

        async persist(issue) {
            if (this.saving) return;

            this.saving = true;
            this.errors = {};

            try {
                if (this.online) {
                    await this.persistOnline(issue);

                    return;
                }

                await this.persistOffline(issue);
            } finally {
                this.saving = false;
            }
        },

        async persistOnline(issue) {
            const result = await this.$wire.save(this.payload(), issue);

            if (! result?.ok) {
                this.errors = result?.errors ?? { form: ['Something went wrong. Please try again.'] };

                return;
            }

            window.location.href = result.redirect;
        },

        /**
         * Saves without a connection.
         *
         * An issued invoice takes a number from the block this device leased
         * while it still had signal, so the paper handed to the customer carries
         * its final number. If the block is spent there is no honest way to
         * issue — inventing a number would be rejected on sync *after* the
         * document was already in someone's hands — so the form says so and
         * offers a draft instead.
         */
        async persistOffline(issue) {
            if (! this.validate(issue)) return;

            const sync = window.opesSync;

            if (! sync) {
                this.errors = { form: ['Offline saving is not available on this device.'] };

                return;
            }

            let number = null;

            if (issue) {
                number = await sync.leases.take(this.entityType);

                if (! number) {
                    this.errors = {
                        form: ['This device has run out of pre-issued numbers. Save a draft and issue it once you are back online.'],
                    };

                    return;
                }
            }

            const lines = this.lines.map((line, index) => ({
                description: String(line.description).trim(),
                quantity: parseFloat(line.quantity),
                unit: 'unit',
                unit_price: parseFloat(line.unit_price),
                line_total: Math.round(this.lineTotal(line) * 100) / 100,
                sort_order: index,
            }));

            const subtotal = Math.round(lines.reduce((sum, line) => sum + line.line_total, 0) * 100) / 100;

            const record = await sync.writer.create(
                'document',
                {
                    type: this.entityType,
                    contact_id: this.contactId,
                    status: issue ? 'issued' : 'draft',
                    issue_date: this.issueDate,
                    due_date: this.dueDate || null,
                    currency: this.currency,
                    subtotal,
                    total: subtotal,
                    amount_paid: 0,
                    balance: subtotal,
                    notes: this.notes || null,
                },
                { lines, assigned_number: number },
            );

            this.savedOffline = {
                number,
                total: this.format(subtotal),
                customer: this.contact?.name ?? 'Customer',
                id: record.id,
            };
        },

        startAnother() {
            this.savedOffline = null;
            this.clearContact();
            this.lines = [this.blankLine()];
            this.notes = '';
            this.errors = {};
        },
    };
}
