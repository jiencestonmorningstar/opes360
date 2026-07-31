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
}
