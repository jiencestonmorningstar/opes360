import { money } from '../support/money.js';

/**
 * The record-payment panel.
 *
 * Taking cash is the one thing a trader cannot postpone until the signal comes
 * back, so this panel owns its state and can complete with no connection: the
 * receipt takes a number from the device's own lease, and the envelope carries
 * the id the payment was born with so a retry after a lost response is
 * recognised rather than charging twice.
 *
 * The invoice may have moved while the device was away — someone else may have
 * settled it. That conflict is detected server-side on sync and surfaces in the
 * failed-changes banner; it is not something the device can rule out locally.
 */
export default function paymentPanel(config) {
    return {
        documentId: config.documentId,
        contactId: config.contactId,
        currency: config.currency,
        balance: Number(config.balance) || 0,

        open: false,
        amount: '',
        method: 'cash',
        reference: '',

        errors: {},
        saving: false,
        online: navigator.onLine,
        receiptsLeft: 0,
        savedOffline: null,

        init() {
            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));

            this.refreshReceiptCount();
            window.opesSync?.onChange(() => this.refreshReceiptCount());
        },

        async refreshReceiptCount() {
            this.receiptsLeft = (await window.opesSync?.leases.remaining('receipt')) ?? 0;
        },

        openPanel() {
            this.open = true;
            this.errors = {};
            // Prefilled with the outstanding balance — settling in full is the
            // common case, so part-payment means editing down rather than
            // typing from scratch.
            this.amount = this.balance.toFixed(2);
        },

        close() {
            this.open = false;
        },

        format(amount) {
            return money(amount, this.currency);
        },

        error(key) {
            const value = this.errors[key];

            return Array.isArray(value) ? value[0] : value;
        },

        validate() {
            const errors = {};
            const amount = parseFloat(this.amount);

            if (! (amount > 0)) {
                errors.amount = ['Enter an amount greater than zero.'];
            } else if (Math.round(amount * 100) > Math.round(this.balance * 100)) {
                errors.amount = [`That is more than the ${this.format(this.balance)} still owing.`];
            }

            this.errors = errors;

            return Object.keys(errors).length === 0;
        },

        async submit() {
            if (this.saving) return;

            this.saving = true;
            this.errors = {};

            try {
                if (this.online) {
                    const result = await this.$wire.recordPayment({
                        amount: this.amount,
                        method: this.method,
                        reference: this.reference,
                    });

                    if (! result?.ok) {
                        this.errors = result?.errors ?? { amount: ['Something went wrong. Please try again.'] };

                        return;
                    }

                    window.location.reload();

                    return;
                }

                await this.submitOffline();
            } finally {
                this.saving = false;
            }
        },

        async submitOffline() {
            if (! this.validate()) return;

            const sync = window.opesSync;

            if (! sync) {
                this.errors = { amount: ['Offline payments are not available on this device.'] };

                return;
            }

            // A receipt is handed over the moment it is taken, so it needs its
            // final number now. Without one there is no honest way to issue it.
            const number = await sync.leases.take('receipt');

            if (! number) {
                this.errors = {
                    amount: ['This device has run out of pre-issued receipt numbers. Reconnect to take this payment.'],
                };

                return;
            }

            const amount = Math.round(parseFloat(this.amount) * 100) / 100;

            await sync.writer.create(
                'payment',
                {
                    document_id: this.documentId,
                    contact_id: this.contactId,
                    amount,
                    method: this.method,
                    currency: this.currency,
                    reference: this.reference || null,
                },
                { assigned_number: number },
            );

            this.savedOffline = { number, amount: this.format(amount) };
            this.open = false;
            await this.refreshReceiptCount();
        },
    };
}
