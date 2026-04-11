<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierAllocationController extends Controller
{
    /**
     * GET /purchases/supplier-allocations?supplier_id=
     *
     * Returns open (unallocated / partially allocated) supplier transactions:
     *  - Posted supplier credit notes (supplier_credit_notes)
     *  - Received purchase orders that are invoiced (type=invoice)
     *  - Direct GRN orders (type=grn, status=received)
     *
     * Each row shows: transaction_type, reference, date, total, allocated, left_to_allocate
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        $supplierId = $request->supplier_id;
        $rows = collect();

        // ── Credit notes (money owed to us by supplier) ───────────────────
        $creditNotes = DB::table('supplier_credit_notes')
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->select(
                DB::raw("'Credit Note' as transaction_type"),
                'id',
                'scn_no as reference',
                'date',
                'total',
                DB::raw('0 as allocated'),
                'total as left_to_allocate'
            )
            ->get();
        $rows = $rows->concat($creditNotes);

        // ── Supplier invoices / direct GRNs (money we owe supplier) ──────
        $invoices = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereIn('type', ['grn', 'invoice'])
            ->whereIn('status', ['received', 'ceo_approved'])
            ->select(
                DB::raw("CASE type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END as transaction_type"),
                'id',
                'po_no as reference',
                DB::raw('order_date as date'),
                'amount_total as total',
                DB::raw('0 as allocated'),
                'amount_total as left_to_allocate'
            )
            ->get();
        $rows = $rows->concat($invoices);

        // Sort by date ascending
        $rows = $rows->sortBy('date')->values();

        return ApiResponse::success($rows, 'Supplier transactions retrieved');
    }

    /**
     * GET /purchases/supplier-allocation-inquiry
     *
     * Full allocation inquiry — all transaction types with debit/credit/allocated/balance.
     * Filters: supplier_id?, from, to, type?, show_settled (bool)
     */
    public function inquiry(Request $request): JsonResponse
    {
        $from        = $request->get('from', now()->startOfMonth()->toDateString());
        $to          = $request->get('to', now()->toDateString());
        $supplierId  = $request->get('supplier_id');
        $type        = $request->get('type');        // optional filter
        $showSettled = filter_var($request->get('show_settled', false), FILTER_VALIDATE_BOOLEAN);

        $rows = collect();

        // ── Supplier Invoices & GRN Receipts ──────────────────────────────
        if (!$type || in_array($type, ['Supplier Invoice', 'GRN Receipt'])) {
            $q = DB::table('purchase_orders as po')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
                ->leftJoin('payment_voucher_allocations as pva', function ($j) {
                    $j->on('pva.transaction_id', '=', 'po.id')
                      ->whereIn('pva.transaction_type', ['Supplier Invoice', 'GRN Receipt']);
                })
                ->whereIn('po.type', ['grn', 'invoice'])
                ->whereIn('po.status', ['received', 'ceo_approved'])
                ->whereBetween(DB::raw('DATE(po.order_date)'), [$from, $to]);

            if ($supplierId) {
                $q->where('po.supplier_id', $supplierId);
            }
            if ($type) {
                $q->where(DB::raw("CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END"), $type);
            }

            $invoices = $q->select(
                DB::raw("CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END as type"),
                'po.id',
                'po.po_no as reference',
                's.supplierName as supplier',
                'po.supplier_reference as supp_reference',
                DB::raw('DATE(po.order_date) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                DB::raw('0 as debit'),
                'po.amount_total as credit',
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as allocated'),
                DB::raw('po.amount_total - COALESCE(SUM(pva.this_allocation),0) as balance')
            )
            ->groupBy('po.id', 'po.type', 'po.po_no', 's.supplierName', 'po.supplier_reference', 'po.order_date', 'po.amount_total')
            ->get();

            $rows = $rows->concat($invoices);
        }

        // ── Supplier Credit Notes ─────────────────────────────────────────
        if (!$type || $type === 'Credit Note') {
            $q = DB::table('supplier_credit_notes as scn')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'scn.supplier_id')
                ->leftJoin('payment_voucher_allocations as pva', function ($j) {
                    $j->on('pva.transaction_id', '=', 'scn.id')
                      ->where('pva.transaction_type', 'Credit Note');
                })
                ->where('scn.status', 'posted')
                ->whereBetween(DB::raw('DATE(scn.date)'), [$from, $to]);

            if ($supplierId) {
                $q->where('scn.supplier_id', $supplierId);
            }

            $creditNotes = $q->select(
                DB::raw("'Credit Note' as type"),
                'scn.id',
                'scn.scn_no as reference',
                's.supplierName as supplier',
                DB::raw('NULL as supp_reference'),
                DB::raw('DATE(scn.date) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                'scn.total as debit',
                DB::raw('0 as credit'),
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as allocated'),
                DB::raw('scn.total - COALESCE(SUM(pva.this_allocation),0) as balance')
            )
            ->groupBy('scn.id', 'scn.scn_no', 's.supplierName', 'scn.date', 'scn.total')
            ->get();

            $rows = $rows->concat($creditNotes);
        }

        // ── Supplier Payments (posted payment vouchers) ───────────────────
        if (!$type || $type === 'Supplier Payment') {
            $q = DB::table('payment_vouchers as pv')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'pv.supplier_id')
                ->leftJoin('payment_voucher_allocations as pva', 'pva.payment_voucher_id', '=', 'pv.id')
                ->where('pv.status', 'posted')
                ->whereBetween(DB::raw('DATE(pv.date_paid)'), [$from, $to]);

            if ($supplierId) {
                $q->where('pv.supplier_id', $supplierId);
            }

            $payments = $q->select(
                DB::raw("'Supplier Payment' as type"),
                'pv.id',
                'pv.pvn_no as reference',
                's.supplierName as supplier',
                'pv.reference as supp_reference',
                DB::raw('DATE(pv.date_paid) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                'pv.amount as debit',
                DB::raw('0 as credit'),
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as allocated'),
                DB::raw('pv.amount - COALESCE(SUM(pva.this_allocation),0) as balance')
            )
            ->groupBy('pv.id', 'pv.pvn_no', 's.supplierName', 'pv.reference', 'pv.date_paid', 'pv.amount')
            ->get();

            $rows = $rows->concat($payments);
        }

        // Sort by date
        $rows = $rows->sortBy('date')->values();

        // Filter settled (balance = 0) unless show_settled
        if (!$showSettled) {
            $rows = $rows->filter(fn($r) => round((float)$r->balance, 4) != 0)->values();
        }

        return ApiResponse::success($rows, 'Allocation inquiry retrieved');
    }

    /**
     * GET /purchases/supplier-transaction-inquiry
     *
     * Normal Supplier Inquiry — all supplier transactions with a single Amount column.
     * Payments are negative (money out), invoices/GRNs/credit notes are positive.
     * Filters: supplier_id?, from, to, type?
     */
    public function transactionInquiry(Request $request): JsonResponse
    {
        $from       = $request->get('from', now()->subDays(30)->toDateString());
        $to         = $request->get('to', now()->toDateString());
        $supplierId = $request->get('supplier_id');
        $type       = $request->get('type');

        $rows = collect();

        // ── Supplier Invoices & GRN Receipts ──────────────────────────────
        if (!$type || in_array($type, ['Supplier Invoice', 'GRN Receipt'])) {
            $q = DB::table('purchase_orders as po')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
                ->whereIn('po.type', ['grn', 'invoice'])
                ->whereIn('po.status', ['received', 'ceo_approved'])
                ->whereBetween(DB::raw('DATE(po.order_date)'), [$from, $to]);

            if ($supplierId) $q->where('po.supplier_id', $supplierId);
            if ($type)       $q->where(DB::raw("CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END"), $type);

            $rows = $rows->concat($q->select(
                DB::raw("CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END as type"),
                'po.id',
                'po.po_no as reference',
                's.supplierName as supplier',
                'po.supplier_reference as suppliers_reference',
                DB::raw('DATE(po.order_date) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                'po.amount_total as amount'
            )->get());
        }

        // ── Supplier Credit Notes ─────────────────────────────────────────
        if (!$type || $type === 'Credit Note') {
            $q = DB::table('supplier_credit_notes as scn')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'scn.supplier_id')
                ->where('scn.status', 'posted')
                ->whereBetween(DB::raw('DATE(scn.date)'), [$from, $to]);

            if ($supplierId) $q->where('scn.supplier_id', $supplierId);

            $rows = $rows->concat($q->select(
                DB::raw("'Credit Note' as type"),
                'scn.id',
                'scn.scn_no as reference',
                's.supplierName as supplier',
                DB::raw('NULL as suppliers_reference'),
                DB::raw('DATE(scn.date) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                'scn.total as amount'
            )->get());
        }

        // ── Supplier Payments (posted payment vouchers) ───────────────────
        if (!$type || $type === 'Supplier Payment') {
            $q = DB::table('payment_vouchers as pv')
                ->leftJoin('suppliers as s', 's.supplierId', '=', 'pv.supplier_id')
                ->where('pv.status', 'posted')
                ->whereBetween(DB::raw('DATE(pv.date_paid)'), [$from, $to]);

            if ($supplierId) $q->where('pv.supplier_id', $supplierId);

            $rows = $rows->concat($q->select(
                DB::raw("'Supplier Payment' as type"),
                'pv.id',
                'pv.pvn_no as reference',
                's.supplierName as supplier',
                'pv.reference as suppliers_reference',
                DB::raw('DATE(pv.date_paid) as date'),
                DB::raw('NULL as due_date'),
                'pv.type as currency_type',
                DB::raw("'KES' as currency"),
                DB::raw('-pv.amount as amount')   // negative — money going out
            )->get());
        }

        $rows = $rows->sortBy('date')->values();

        return ApiResponse::success($rows, 'Supplier transactions retrieved');
    }
}
