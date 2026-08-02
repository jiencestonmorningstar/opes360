<?php

namespace App\Enums;

enum DocumentType: string
{
    case Quotation = 'quotation';
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case PurchaseOrder = 'purchase_order';
    case DeliveryNote = 'delivery_note';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case WorkOrder = 'work_order';
    case GoodsReceivedNote = 'goods_received_note';
    case Waybill = 'waybill';

    public function label(): string
    {
        return match ($this) {
            self::Quotation => 'Quotation',
            self::Invoice => 'Invoice',
            self::Proforma => 'Proforma Invoice',
            self::PurchaseOrder => 'Purchase Order',
            self::DeliveryNote => 'Delivery Note',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::WorkOrder => 'Work Order',
            self::GoodsReceivedNote => 'Goods Received Note',
            self::Waybill => 'Waybill',
        };
    }

    /** Prefix used when composing the human-facing document number. */
    public function prefix(): string
    {
        return match ($this) {
            self::Quotation => 'QUO',
            self::Invoice => 'INV',
            self::Proforma => 'PRO',
            self::PurchaseOrder => 'PO',
            self::DeliveryNote => 'DN',
            self::CreditNote => 'CN',
            self::DebitNote => 'DBN',
            self::WorkOrder => 'WO',
            self::GoodsReceivedNote => 'GRN',
            self::Waybill => 'WB',
        };
    }

    /** Only these types move money onto a customer's account. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Invoice, self::DebitNote], true);
    }

    /**
     * A credit note is the one document that moves money the other way: it
     * cancels part or all of an invoice the customer has already been given.
     */
    public function isCreditNote(): bool
    {
        return $this === self::CreditNote;
    }

    /**
     * Whether issuing this changes what a customer owes — in either direction.
     *
     * A quotation is an offer and a delivery note is a receipt for goods; both
     * can be issued all day without a franc moving. These three cannot.
     */
    public function affectsCustomerAccount(): bool
    {
        return $this->isReceivable() || $this->isCreditNote();
    }

    /**
     * +1 when issuing it adds to what the customer owes, -1 when it takes
     * away, 0 when it does neither.
     *
     * Everything that has to treat invoices and credit notes as mirror images
     * — the journal entry, the stock movement, the customer's balance — reads
     * this rather than carrying its own `if credit note` branch, which is how
     * the three of them stay consistent with each other.
     */
    public function customerAccountSign(): int
    {
        return match (true) {
            $this->isReceivable() => 1,
            $this->isCreditNote() => -1,
            default => 0,
        };
    }
}
