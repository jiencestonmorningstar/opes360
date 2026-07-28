/**
 * A simple record form that keeps working with no connection.
 *
 * Shared by the customer and product forms — both are "fill in fields, save one
 * row", and the offline path is identical for each: validate on the client,
 * write the local mirror, queue the envelope. Anything more structured (the
 * document composer, with its line items and leased numbers) has its own
 * component.
 *
 * Editing an existing record is deliberately online-only. Two devices editing
 * the same customer while both offline is a merge problem this product does not
 * need to solve today, and last-writer-wins on a customer's bank details is a
 * bad answer. Creating is safe because a new record has no other version.
 */
export default function recordForm(config) {
    return {
        entityType: config.entityType,
        fields: config.fields,
        rules: config.rules ?? {},
        isEdit: config.isEdit ?? false,
        listUrl: config.listUrl,

        form: { ...config.initial },
        errors: {},
        saving: false,
        online: navigator.onLine,
        savedOffline: null,

        init() {
            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));
        },

        error(key) {
            const value = this.errors[key];

            return Array.isArray(value) ? value[0] : value;
        },

        /** Mirrors the server rules so an offline save fails for the same reasons. */
        validate() {
            const errors = {};

            for (const [field, rule] of Object.entries(this.rules)) {
                const value = String(this.form[field] ?? '').trim();

                if (rule.required && value === '') {
                    errors[field] = [rule.message ?? 'This field is required.'];

                    continue;
                }

                if (value !== '' && rule.email && ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) {
                    errors[field] = ['That does not look like an email address.'];
                }

                if (value !== '' && rule.numeric && Number.isNaN(Number(value))) {
                    errors[field] = ['Enter a number.'];
                }
            }

            this.errors = errors;

            return Object.keys(errors).length === 0;
        },

        async save() {
            if (this.saving) return;

            this.saving = true;
            this.errors = {};

            try {
                if (this.online) {
                    const result = await this.$wire.save(this.form);

                    if (! result?.ok) {
                        this.errors = result?.errors ?? { form: ['Something went wrong. Please try again.'] };

                        return;
                    }

                    window.location.href = result.redirect;

                    return;
                }

                await this.saveOffline();
            } finally {
                this.saving = false;
            }
        },

        async saveOffline() {
            if (this.isEdit) {
                this.errors = { form: ['Editing needs a connection. Your changes have not been saved.'] };

                return;
            }

            if (! this.validate()) return;

            const sync = window.opesSync;

            if (! sync) {
                this.errors = { form: ['Offline saving is not available on this device.'] };

                return;
            }

            const record = await sync.writer.create(this.entityType, config.toPayload(this.form));

            this.savedOffline = { name: config.label(this.form), id: record.id };
        },

        startAnother() {
            this.form = { ...config.initial };
            this.savedOffline = null;
            this.errors = {};
        },
    };
}
