<?php

namespace App\Services\Farmers;

use App\Models\MilkPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MilkPurchaseApprovalService
 *
 * Handles all accounting inserts when a milk purchase batch is approved.
 * Follows the legacy "complete-purchase" insertion order exactly:
 *
 *  1. milk_transactions          — generate TID (TIN-XXXXXXXX)
 *  2. purch_data                 — supplier price record (shared table)
 *  3. purchase_orders            — one PO per farmer item (shared table)
 *  4. purchase_order_items       — PO detail line (shared table)
 *  5. milk_grn_batches           — GRN batch (with purch_order_id + tid)
 *  6. milk_grader_collections    — grader collection log (needs grn_batch_id)
 *  7. milk_grn_items             — GRN line item (with po_detail_item_id + tid)
 *  8. milk_supp_invoices         — AP supplier invoice
 *  9. milk_supp_invoice_items    — invoice line linking supp_invoice → grn_item → po_detail
 * 10. stock_movements            — inventory increase (type 25)
 * 11. gld_transactions ×4       — DR Inventory / CR GRN Clearing / CR Payables / DR GRN Clearing
 *
 * Must be called inside a DB::transaction() in the controller.
 */
class MilkPurchaseApprovalService
{
    // ── Transaction type constants (matching legacy FA types) ─────────────────
    const TYPE_GRN_RECEIVE  = 25;   // st_supp_receive — GRN stock receipt
    const TYPE_SUPP_INVOICE = 20;   // st_supp_invoice — supplier AP invoice

    /**
     * Post all accounting records for a milk purchase batch.
     * Throws \RuntimeException on critical setup errors.
     */
    public function postApproval(MilkPurchase $purchase, string $approvedBy): void
    {
        $purchase->loadMissing(['items', 'route', 'shift', 'graderLocation']);

        // ── 1. RAW MILK item master ───────────────────────────────────────────
        $rawMilkItem = DB::table('items')
            ->where(fn ($q) => $q
                ->where('long_description', 'like', '%RAW MILK%')
                ->orWhere('description', 'like', '%RAW MILK%')
            )
            ->first();

        if (! $rawMilkItem) {
            throw new \RuntimeException('RAW MILK stock item not found — configure it in the Items master first.');
        }

        // ── 2. GL accounts ────────────────────────────────────────────────────
        $gl = DB::table('gl_settings')->first();
        $inventoryAccount = $rawMilkItem->inventory_account
            ?? $gl?->items_inventory_account
            ?? '101010';
        $grnClearAccount = $gl?->grn_clearing_account ?? '102110';
        $payableAccount  = $gl?->payable_account       ?? '201020';

        // ── 3. Context ────────────────────────────────────────────────────────
        $locCode     = $purchase->graderLocation?->code ?? '';
        $routeCode   = $purchase->route?->route_code    ?? '';
        $shiftDesc   = $purchase->shift?->description   ?? '';
        $invoiceDate = $purchase->invoice_date->toDateString();
        $ref         = $purchase->reference_no ?? ('MPB-' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT));

        $priceListId = DB::table('inventory_locations')
            ->where('code', $locCode)
            ->value('price_list') ?? '';

        $hasPurchDataTable = Schema::hasTable('milk_purch_data');

        // ── 4. Per-farmer-item loop ───────────────────────────────────────────
        foreach ($purchase->items as $item) {
            // Rejected milk (failed QC at collection) is recorded for
            // reporting/farmer follow-up only — it was never received into
            // inventory and is never paid for, so it must not touch stock,
            // GRN, purchase orders, payables, or the GL in any way.
            //
            // This does NOT affect the grader/transporter's payroll either —
            // a farmer's milk failing QC at the point of collection is the
            // farmer's outcome, not the grader's, so no deduction is raised
            // against them for it. Only milk that was good at collection but
            // is later lost/rejected during transport counts against the
            // grader — see MilkTransferReceptionController, which charges
            // that via GraderDeduction at farmer rate.
            if ($item->quality_status === 'rejected') {
                continue;
            }

            $farmer = DB::table('farmers')->where('id', $item->farmer_id)->first();
            if (! $farmer) {
                continue;
            }

            $supplier = DB::table('suppliers')
                ->where('memberNumber', $farmer->member_no)
                ->first();
            $supplierId = $supplier?->supplierId;

            $qty        = (float) $item->quantity;
            $unitPrice  = (float) $item->unit_price;
            $totalPrice = (float) ($item->total_price ?: round($unitPrice * $qty, 4));
            $uniqueKey  = $item->unique_key ?? Str::uuid()->toString();
            $dueDate    = date('Y-m-t', strtotime($invoiceDate));  // end of month
            $farmerName = $farmer->full_name ?? ('Farmer #' . $farmer->id);

            // ── STEP 1: milk_transactions — generate TID ─────────────────────
            $transactionId = DB::table('milk_transactions')->insertGetId([
                'purchase_id'      => $purchase->id,
                'purchase_item_id' => $item->id,
                'unique_key'       => $uniqueKey,
                'date_of_trans'    => $invoiceDate,
                'status'           => 1,  // 1 = posted
                'dateandtime'      => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $tid = 'TIN-' . str_pad($transactionId, 8, '0', STR_PAD_LEFT);

            // Update the transaction row with the generated TID
            DB::table('milk_transactions')->where('id', $transactionId)->update(['tid' => $tid]);

            // ── STEP 2: purch_data — supplier price record ───────────────────
            // supplier_id+stock_id is a composite PK — only insert when supplier is linked.
            // The table this originally targeted (milk_purch_data) was dropped by
            // 2026_03_19_110008_replace_milk_purch_tables_with_shared.php in favour
            // of a "shared" purch_data table that was never actually migrated —
            // neither exists today, and nothing in the app reads this data back
            // (it's insertOrIgnore, write-only). Guard on the table's existence so
            // approval isn't blocked by this dead write; resurrect the migration
            // if this data turns out to be needed later.
            if ($supplierId && $hasPurchDataTable) {
                DB::table('milk_purch_data')->insertOrIgnore([
                    'purchase_item_id'    => $item->id,
                    'supplier_id'         => $supplierId,
                    'stock_id'            => $rawMilkItem->stock_id,
                    'price'               => $unitPrice,
                    'suppliers_uom'       => 'L',
                    'conversion_factor'   => 1,
                    'supplier_description'=> substr($rawMilkItem->description ?? 'RAW MILK', 0, 50),
                    'tid'                 => $tid,
                    'unique_key'          => $uniqueKey,
                ]);
            }

            // ── STEP 3: purchase_orders — one PO per farmer item ─────────────
            $purchOrderId = DB::table('purchase_orders')->insertGetId([
                'po_no'                 => '',          // filled in after insert
                'type'                  => 'milk',
                'supplier_id'           => $supplierId,
                'reference'             => $ref,
                'supplier_reference'    => $uniqueKey,
                'order_date'            => $invoiceDate,
                'delivery_date'         => $invoiceDate,
                'due_date'              => $dueDate,
                'location_id'           => $locCode,
                'receive_into'          => $locCode,
                'payment_terms'         => '',
                'currency'              => 'KES',
                'exchange_rate'         => 1,
                'status'                => 'approved',
                'raised_by'             => $approvedBy,
                'sub_total'             => $totalPrice,
                'amount_total'          => $totalPrice,
                'internal_memo'         => $farmerName,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
            // Set po_no to MPO-XXXXXX based on the generated id
            $poNo = 'MPO-' . str_pad($purchOrderId, 6, '0', STR_PAD_LEFT);
            DB::table('purchase_orders')->where('id', $purchOrderId)->update(['po_no' => $poNo]);

            // ── STEP 4: purchase_order_items — PO detail line ────────────────
            $poDetailItemId = DB::table('purchase_order_items')->insertGetId([
                'po_id'                  => $purchOrderId,
                'stock_id'               => $rawMilkItem->stock_id,
                'description'            => $rawMilkItem->description ?? 'RAW MILK',
                'qty'                    => $qty,
                'unit'                   => 'L',
                'required_delivery_date' => $invoiceDate,
                'price_before_tax'       => $unitPrice,
                'discount_amt'           => 0,
                'line_total'             => $totalPrice,
            ]);

            // ── STEP 5: milk_grn_batches — GRN batch ─────────────────────────
            $grnBatchId = DB::table('milk_grn_batches')->insertGetId([
                'purchase_id'      => $purchase->id,
                'purchase_item_id' => $item->id,
                'unique_key'       => $uniqueKey,
                'supplier_id'      => $supplierId,
                'purch_order_id'   => $purchOrderId,
                'reference'        => $ref,
                'delivery_date'    => $invoiceDate,
                'loc_code'         => $locCode,
                'tid'              => $tid,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // ── STEP 6: milk_grader_collections ──────────────────────────────
            DB::table('milk_grader_collections')->insert([
                'purchase_id'      => $purchase->id,
                'purchase_item_id' => $item->id,
                'grn_batch_id'     => $grnBatchId,
                'quantity'         => $qty,
                'rate'             => $unitPrice,
                'location'         => $locCode,
                'date_collected'   => $invoiceDate,
                'grader_id'        => $locCode,
                'price_list_id'    => $priceListId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // ── STEP 7: milk_grn_items — GRN line item ────────────────────────
            $grnItemId = DB::table('milk_grn_items')->insertGetId([
                'grn_batch_id'      => $grnBatchId,
                'purchase_item_id'  => $item->id,
                'unique_key'        => $uniqueKey,
                'po_detail_item_id' => $poDetailItemId,
                'item_code'         => $rawMilkItem->stock_id,
                'description'       => $rawMilkItem->description ?? 'RAW MILK',
                'qty_received'      => $qty,
                'qty_invoiced'      => $qty,
                'unit_price'        => $unitPrice,
                'tid'               => $tid,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // ── STEP 8: milk_supp_invoices — AP supplier invoice ──────────────
            $transNo = $this->nextSuppTransNo();
            $suppInvoiceId = DB::table('milk_supp_invoices')->insertGetId([
                'trans_no'    => $transNo,
                'unique_key'  => $uniqueKey,
                'purchase_id' => $purchase->id,
                'grn_batch_id'=> $grnBatchId,
                'supplier_id' => $supplierId,
                'reference'   => $ref,
                'tran_date'   => $invoiceDate,
                'due_date'    => $dueDate,
                'amount'      => $totalPrice,
                'shift'       => $shiftDesc,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // ── STEP 9: milk_supp_invoice_items — invoice line ────────────────
            DB::table('milk_supp_invoice_items')->insert([
                'purchase_id'       => $purchase->id,
                'supp_invoice_id'   => $suppInvoiceId,
                'grn_item_id'       => $grnItemId,
                'po_detail_item_id' => $poDetailItemId,
                'unique_key'        => $uniqueKey,
                'supp_trans_no'     => $transNo,
                'supp_trans_type'   => self::TYPE_SUPP_INVOICE,
                'gl_code'           => $inventoryAccount,
                'stock_id'          => $rawMilkItem->stock_id,
                'description'       => $rawMilkItem->description ?? 'RAW MILK',
                'quantity'          => $qty,
                'unit_price'        => $unitPrice,
                'unit_tax'          => 0,
                'dimension_id'      => 0,
                'dimension2_id'     => 0,
                'tid'               => $tid,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // ── STEP 10: stock_movements — inventory increase ─────────────────
            DB::table('stock_movements')->insert([
                'trans_no'      => $purchase->id,
                'stock_id'      => $rawMilkItem->stock_id,
                'type'          => self::TYPE_GRN_RECEIVE,
                'loc_code'      => $locCode,
                'route_id'      => $routeCode,
                'shift'         => $shiftDesc,
                'tran_date'     => $invoiceDate,
                'qty'           => $qty,
                'price'         => $unitPrice,
                'standard_cost' => $unitPrice,
                'reference'     => $ref,
                'comments'      => $farmerName,
                'unique_key'    => $uniqueKey,
            ]);

            // ── STEP 11: gld_transactions ×4 ─────────────────────────────────
            $glBase = [
                'trans_no'   => $purchase->id,
                'tran_date'  => $invoiceDate,
                'reference'  => $ref,
                'created_by' => $approvedBy,
            ];

            // i.  DR Inventory account (GRN receive side)
            DB::table('gld_transactions')->insert(array_merge($glBase, [
                'type'         => self::TYPE_GRN_RECEIVE,
                'account_code' => $inventoryAccount,
                'narration'    => $rawMilkItem->stock_id . ' — ' . $farmerName,
                'amount'       => $totalPrice,
            ]));

            // ii. CR GRN Clearing account (GRN receive side)
            DB::table('gld_transactions')->insert(array_merge($glBase, [
                'type'         => self::TYPE_GRN_RECEIVE,
                'account_code' => $grnClearAccount,
                'narration'    => 'GRN Clearing — ' . $farmerName,
                'amount'       => -$totalPrice,
            ]));

            // iii. CR Payables account (supplier invoice side)
            DB::table('gld_transactions')->insert(array_merge($glBase, [
                'type'         => self::TYPE_SUPP_INVOICE,
                'account_code' => $payableAccount,
                'narration'    => 'Payable — ' . $farmerName,
                'amount'       => -$totalPrice,
            ]));

            // iv. DR GRN Clearing account (supplier invoice side — balances iii)
            DB::table('gld_transactions')->insert(array_merge($glBase, [
                'type'         => self::TYPE_SUPP_INVOICE,
                'account_code' => $grnClearAccount,
                'narration'    => 'GRN Clearing — ' . $farmerName,
                'amount'       => $totalPrice,
            ]));
        }

        // ── 5. Mark batch as approved ─────────────────────────────────────────
        $purchase->update([
            'status'      => 'approved',
            'approved_by' => $approvedBy,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Get the next sequential trans_no for milk supplier invoices.
     * Uses a pessimistic lock so concurrent approvals get unique numbers.
     * Must be called within an outer DB transaction.
     */
    private function nextSuppTransNo(): int
    {
        $max = DB::table('milk_supp_invoices')
            ->lockForUpdate()
            ->max('trans_no') ?? 0;

        return $max + 1;
    }
}
