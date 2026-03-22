<?php

namespace Database\Seeders;

use App\Models\TransactionReference;
use Illuminate\Database\Seeder;

class TransactionReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // ── Banking / GL ───────────────────────────────────────────────
            ['trans_type' => 'journal_entry',        'trans_name' => 'Journal Entry',           'prefix' => 'JNL',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'bank_payment',          'trans_name' => 'Bank Payment',            'prefix' => '',     'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'bank_deposit',          'trans_name' => 'Bank Deposit',            'prefix' => '',     'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'funds_transfer',        'trans_name' => 'Funds Transfer',          'prefix' => 'FT',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'payment_voucher',       'trans_name' => 'Payment Voucher',         'prefix' => 'PV',   'pattern' => '{001}/{YYYY}'],

            // ── Sales ──────────────────────────────────────────────────────
            ['trans_type' => 'sales_quotation',       'trans_name' => 'Sales Quotation',         'prefix' => 'QT',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'sales_order',           'trans_name' => 'Sales Order',             'prefix' => 'SO',   'pattern' => 'SO{001}/{YYYY}'],
            ['trans_type' => 'sales_invoice',         'trans_name' => 'Sales Invoice',           'prefix' => '',     'pattern' => 'INV/{001}/{YYYY}'],
            ['trans_type' => 'customer_credit_note',  'trans_name' => 'Customer Credit Note',    'prefix' => '',     'pattern' => 'CN/{001}/{YYYY}'],
            ['trans_type' => 'customer_payment',      'trans_name' => 'Customer Payment',        'prefix' => '',     'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'customer_deposit',      'trans_name' => 'Customer Deposit',        'prefix' => 'CD',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'delivery_note',         'trans_name' => 'Delivery Note',           'prefix' => '',     'pattern' => 'DN/{001}/{YYYY}'],

            // ── Purchases ──────────────────────────────────────────────────
            ['trans_type' => 'purchase_order',        'trans_name' => 'Purchase Order',          'prefix' => 'PO',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'po_delivery',           'trans_name' => 'Purchase Order Delivery', 'prefix' => '',     'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'goods_received',        'trans_name' => 'Goods Received Note',     'prefix' => 'GRN',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'supplier_invoice',      'trans_name' => 'Supplier Invoice',        'prefix' => 'SI',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'supplier_credit_note',  'trans_name' => 'Supplier Credit Note',    'prefix' => 'SCN',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'supplier_payment',      'trans_name' => 'Supplier Payment',        'prefix' => '',     'pattern' => '{001}/{YYYY}'],

            // ── Inventory ──────────────────────────────────────────────────
            ['trans_type' => 'location_transfer',     'trans_name' => 'Location Transfer',       'prefix' => 'LT',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'inventory_adjustment',  'trans_name' => 'Inventory Adjustment',    'prefix' => 'IA',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'consumable_issue',      'trans_name' => 'Consumable Issue',        'prefix' => 'CONS', 'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'cost_update',           'trans_name' => 'Cost Update',             'prefix' => 'CU',   'pattern' => '{001}/{YYYY}'],

            // ── Manufacturing ──────────────────────────────────────────────
            ['trans_type' => 'work_order',            'trans_name' => 'Work Order',              'prefix' => 'WO',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'work_order_issue',      'trans_name' => 'Work Order Issue',        'prefix' => 'WOI',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'work_order_production', 'trans_name' => 'Work Order Production',   'prefix' => 'WOP',  'pattern' => '{001}/{YYYY}'],

            // ── Payroll ────────────────────────────────────────────────────
            ['trans_type' => 'payroll_run',           'trans_name' => 'Payroll Run',             'prefix' => 'PAY',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'payroll_payment',       'trans_name' => 'Payroll Payment',         'prefix' => '',     'pattern' => '{001}/{YYYY}'],

            // ── Dimensions & Other ─────────────────────────────────────────
            ['trans_type' => 'dimension',             'trans_name' => 'Dimension Entry',         'prefix' => '',     'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'asset_acquisition',     'trans_name' => 'Asset Acquisition',       'prefix' => 'FA',   'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'asset_disposal',        'trans_name' => 'Asset Disposal',          'prefix' => 'FAD',  'pattern' => '{001}/{YYYY}'],
            ['trans_type' => 'asset_depreciation',    'trans_name' => 'Asset Depreciation',      'prefix' => 'DEP',  'pattern' => '{001}/{YYYY}'],
        ];

        foreach ($types as $i => $row) {
            TransactionReference::firstOrCreate(
                ['trans_type' => $row['trans_type']],
                array_merge($row, ['is_default' => true, 'memo' => '', 'inactive' => false, 'sort_order' => $i])
            );
        }
    }
}
