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
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as pv_allocated'),
                'po.amount_total as total_amount'
            )
            ->groupBy('po.id', 'po.type', 'po.po_no', 's.supplierName', 'po.supplier_reference', 'po.order_date', 'po.amount_total')
            ->get();

            // Add journal allocations for each invoice/GRN
            $poIds = $invoices->pluck('id')->toArray();
            $jnlAllocated = DB::table('supplier_journal_allocations')
                ->whereIn('transaction_id', $poIds)
                ->whereIn('transaction_type', ['Supplier Invoice', 'GRN Receipt'])
                ->select('transaction_id', DB::raw('SUM(amount) as jnl_allocated'))
                ->groupBy('transaction_id')
                ->get()
                ->keyBy('transaction_id');

            $invoices = $invoices->map(function ($row) use ($jnlAllocated) {
                $pvAlloc  = (float)$row->pv_allocated;
                $jnlAlloc = (float)($jnlAllocated[$row->id]->jnl_allocated ?? 0);
                $allocated = $pvAlloc + $jnlAlloc;
                $row->allocated = $allocated;
                $row->balance   = (float)$row->total_amount - $allocated;
                unset($row->pv_allocated, $row->total_amount);
                return $row;
            });

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
     * GET /purchases/supplier-transaction-allocations?transaction_id=&transaction_type=
     *
     * Returns payment voucher allocations made against a specific transaction.
     */
    public function transactionAllocations(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_id'   => 'required|integer',
            'transaction_type' => 'required|string',
        ]);

        $txId   = $request->transaction_id;
        $txType = $request->transaction_type;

        // Payment voucher allocations
        $pvAllocs = DB::table('payment_voucher_allocations as pva')
            ->join('payment_vouchers as pv', 'pv.id', '=', 'pva.payment_voucher_id')
            ->where('pva.transaction_id', $txId)
            ->where('pva.transaction_type', $txType)
            ->select(
                'pv.pvn_no as payment_ref',
                'pv.date_paid as date',
                'pv.type as payment_type',
                'pv.status',
                'pva.this_allocation as amount',
                DB::raw("'payment' as source")
            )
            ->orderBy('pv.date_paid')
            ->get();

        // Journal allocations
        $jnlAllocs = DB::table('supplier_journal_allocations as sja')
            ->join('journal_entries as je', 'je.id', '=', 'sja.journal_id')
            ->where('sja.transaction_id', $txId)
            ->where('sja.transaction_type', $txType)
            ->select(
                'je.journal_no as payment_ref',
                'je.journal_date as date',
                DB::raw("'journal' as payment_type"),
                'je.status',
                'sja.amount',
                DB::raw("'journal' as source")
            )
            ->orderBy('je.journal_date')
            ->get();

        $allocs = $pvAllocs->concat($jnlAllocs)->sortBy('date')->values();

        return ApiResponse::success($allocs, 'Transaction allocations retrieved');
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
                'po.supplier_id',
                'po.po_no as reference',
                's.supplierName as supplier',
                'po.supplier_reference as suppliers_reference',
                DB::raw('DATE(po.order_date) as date'),
                DB::raw('NULL as due_date'),
                DB::raw("'KES' as currency"),
                'po.amount_total as amount'
            )->get());
        }

        // ── Flag GRN rows that have been invoiced ─────────────────────────
        $grnRows = $rows->where('type', 'GRN Receipt');
        if ($grnRows->isNotEmpty()) {
            $grnIds = $grnRows->pluck('id');

            // Check via source_grn_id link
            $invoicedViaSourceGrn = DB::table('purchase_orders')
                ->where('type', 'invoice')
                ->whereIn('source_grn_id', $grnIds)
                ->pluck('source_grn_id')
                ->unique();

            // Check via grn_item_id link (items on invoices pointing back to GRN item ids)
            $grnItemToGrn = DB::table('purchase_order_items')
                ->whereIn('po_id', $grnIds)
                ->pluck('po_id', 'id'); // [item_id => grn_id]

            $invoicedItemIds = DB::table('purchase_order_items as poi')
                ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
                ->where('po.type', 'invoice')
                ->whereNotNull('poi.grn_item_id')
                ->whereIn('poi.grn_item_id', $grnItemToGrn->keys())
                ->pluck('poi.grn_item_id')
                ->unique();

            $invoicedViaItems = $grnItemToGrn->only($invoicedItemIds->all())->values()->unique();

            $invoicedGrnIds = $invoicedViaSourceGrn->merge($invoicedViaItems)->unique();

            $rows = $rows->map(function ($row) use ($invoicedGrnIds) {
                $row->has_invoice = ($row->type === 'GRN Receipt') && $invoicedGrnIds->contains($row->id);
                return $row;
            });
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
                'scn.supplier_id',
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
                'pv.supplier_id',
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

    /**
     * GET /purchases/available-credit-notes?supplier_id=
     *
     * Returns posted supplier credit notes with remaining unallocated balance.
     */
    public function availableCreditNotes(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        $supplierId = $request->supplier_id;

        $credits = DB::table('supplier_credit_notes as scn')
            ->leftJoin('supplier_credit_note_allocations as sca', 'sca.scn_id', '=', 'scn.id')
            ->where('scn.supplier_id', $supplierId)
            ->where('scn.status', 'posted')
            ->select(
                'scn.id',
                'scn.scn_no as reference',
                'scn.date',
                'scn.total as amount',
                DB::raw('COALESCE(SUM(sca.amount),0) as allocated'),
                DB::raw('scn.total - COALESCE(SUM(sca.amount),0) as available')
            )
            ->groupBy('scn.id', 'scn.scn_no', 'scn.date', 'scn.total')
            ->havingRaw('scn.total - COALESCE(SUM(sca.amount),0) > 0.0001')
            ->orderBy('scn.date')
            ->get();

        return ApiResponse::success($credits, 'Available credit notes retrieved');
    }

    /**
     * POST /purchases/supplier-credit-note-allocations
     *
     * Allocates a credit note against a supplier invoice or GRN.
     */
    public function allocateCreditNote(Request $request): JsonResponse
    {
        $request->validate([
            'scn_id'           => 'required|integer|exists:supplier_credit_notes,id',
            'supplier_id'      => 'required|integer',
            'transaction_type' => 'required|string|in:Supplier Invoice,GRN Receipt',
            'transaction_id'   => 'required|integer',
            'amount'           => 'required|numeric|min:0.0001',
        ]);

        $scn = DB::table('supplier_credit_notes')->find($request->scn_id);
        if (!$scn || $scn->status !== 'posted') {
            return ApiResponse::error('Credit note must be posted to allocate', 422);
        }

        $allocated = DB::table('supplier_credit_note_allocations')
            ->where('scn_id', $request->scn_id)
            ->sum('amount');

        $available = (float)$scn->total - (float)$allocated;
        if ((float)$request->amount > $available + 0.0001) {
            return ApiResponse::error("Allocation exceeds available credit note balance ({$available})", 422);
        }

        DB::table('supplier_credit_note_allocations')->insert([
            'scn_id'           => $request->scn_id,
            'transaction_type' => $request->transaction_type,
            'transaction_id'   => $request->transaction_id,
            'supplier_id'      => $request->supplier_id,
            'amount'           => $request->amount,
            'created_by'       => auth()->user()?->user_id ?? 'system',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return ApiResponse::created([], 'Credit note allocated successfully');
    }

    /**
     * GET /purchases/credit-note-allocated-invoices?scn_id=
     *
     * Returns invoices/GRNs that this credit note has been applied to.
     */
    public function creditNoteAllocatedInvoices(Request $request): JsonResponse
    {
        $request->validate(['scn_id' => 'required|integer']);

        $allocs = DB::table('supplier_credit_note_allocations as sca')
            ->join('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', 'sca.transaction_id')
                  ->whereIn('sca.transaction_type', ['Supplier Invoice', 'GRN Receipt']);
            })
            ->where('sca.scn_id', $request->scn_id)
            ->select(
                'sca.transaction_type as type',
                'po.id',
                'po.po_no as reference',
                'po.order_date as date',
                'po.amount_total as total_amount',
                'sca.amount as this_allocation'
            )
            ->orderBy('po.order_date')
            ->get();

        return ApiResponse::success($allocs, 'Credit note allocations retrieved');
    }

    /**
     * GET /purchases/invoice-full-detail?transaction_id=&transaction_type=
     *
     * Returns invoice/GRN with its items + all payments/journal/credit allocations.
     */
    public function invoiceFullDetail(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_id'   => 'required|integer',
            'transaction_type' => 'required|string',
        ]);

        $txId   = $request->transaction_id;
        $txType = $request->transaction_type;

        // Payment voucher allocations
        $pvAllocs = DB::table('payment_voucher_allocations as pva')
            ->join('payment_vouchers as pv', 'pv.id', '=', 'pva.payment_voucher_id')
            ->where('pva.transaction_id', $txId)
            ->where('pva.transaction_type', $txType)
            ->select(
                DB::raw("'Supplier Payment' as type"),
                'pv.pvn_no as reference',
                'pv.date_paid as date',
                DB::raw('pv.amount as total_amount'),
                DB::raw('CAST(pv.amount - (SELECT COALESCE(SUM(a2.this_allocation),0) FROM payment_voucher_allocations a2 WHERE a2.payment_voucher_id = pv.id) AS DECIMAL(15,4)) as left_to_allocate'),
                'pva.this_allocation as this_allocation',
                DB::raw("'payment' as source")
            )
            ->get();

        // Journal allocations
        $jnlAllocs = DB::table('supplier_journal_allocations as sja')
            ->join('journal_entries as je', 'je.id', '=', 'sja.journal_id')
            ->where('sja.transaction_id', $txId)
            ->where('sja.transaction_type', $txType)
            ->select(
                DB::raw("'Journal Entry' as type"),
                'je.journal_no as reference',
                'je.journal_date as date',
                DB::raw('(SELECT COALESCE(SUM(jel.credit),0) FROM journal_entry_lines jel WHERE jel.journal_id = je.id) as total_amount'),
                DB::raw('(SELECT COALESCE(SUM(jel.credit),0) FROM journal_entry_lines jel WHERE jel.journal_id = je.id) - COALESCE((SELECT SUM(a2.amount) FROM supplier_journal_allocations a2 WHERE a2.journal_id = je.id AND a2.supplier_id = sja.supplier_id),0) as left_to_allocate'),
                'sja.amount as this_allocation',
                DB::raw("'journal' as source")
            )
            ->get();

        // Credit note allocations
        $cnaAllocs = DB::table('supplier_credit_note_allocations as sca')
            ->join('supplier_credit_notes as scn', 'scn.id', '=', 'sca.scn_id')
            ->where('sca.transaction_id', $txId)
            ->where('sca.transaction_type', $txType)
            ->select(
                DB::raw("'Supplier Credit Note' as type"),
                'scn.scn_no as reference',
                'scn.date',
                'scn.total as total_amount',
                DB::raw('scn.total - COALESCE((SELECT SUM(a2.amount) FROM supplier_credit_note_allocations a2 WHERE a2.scn_id = scn.id),0) as left_to_allocate'),
                'sca.amount as this_allocation',
                DB::raw("'credit_note' as source")
            )
            ->get();

        $allAllocs = $pvAllocs->concat($jnlAllocs)->concat($cnaAllocs)->sortBy('date')->values();
        $totalAllocated = $allAllocs->sum('this_allocation');

        return ApiResponse::success([
            'allocations'     => $allAllocs,
            'total_allocated' => $totalAllocated,
        ], 'Invoice detail retrieved');
    }

    /**
     * GET /purchases/available-payments?supplier_id=
     *
     * Returns posted payment vouchers for the supplier that still have unallocated balance.
     */
    public function availablePayments(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        $payments = DB::table('payment_vouchers as pv')
            ->leftJoin('payment_voucher_allocations as pva', 'pva.payment_voucher_id', '=', 'pv.id')
            ->where('pv.supplier_id', $request->supplier_id)
            ->where('pv.status', 'posted')
            ->select(
                'pv.id',
                'pv.pvn_no as reference',
                'pv.date_paid as date',
                'pv.amount',
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as allocated'),
                DB::raw('pv.amount - COALESCE(SUM(pva.this_allocation),0) as available')
            )
            ->groupBy('pv.id', 'pv.pvn_no', 'pv.date_paid', 'pv.amount')
            ->havingRaw('pv.amount - COALESCE(SUM(pva.this_allocation),0) > 0.0001')
            ->orderBy('pv.date_paid')
            ->get();

        return ApiResponse::success($payments, 'Available payments retrieved');
    }

    /**
     * GET /purchases/available-journals?supplier_id=
     *
     * Returns posted journal entries that have unallocated balance against this supplier.
     * Shows all posted journals with remaining credit balance.
     */
    public function availableJournals(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        $supplierId = $request->supplier_id;

        $journals = DB::table('journal_entries as je')
            ->leftJoin('supplier_journal_allocations as sja', function ($j) use ($supplierId) {
                $j->on('sja.journal_id', '=', 'je.id')
                  ->where('sja.supplier_id', $supplierId);
            })
            ->leftJoin('journal_entry_lines as jel', function ($j) {
                $j->on('jel.journal_id', '=', 'je.id')
                  ->whereColumn('jel.credit', '>', DB::raw('0'));
            })
            ->where('je.status', 'posted')
            ->select(
                'je.id',
                'je.journal_no as reference',
                'je.journal_date as date',
                'je.memo',
                DB::raw('COALESCE(SUM(DISTINCT jel.credit),0) as total_credit'),
                DB::raw('COALESCE(SUM(sja.amount),0) as allocated'),
                DB::raw('COALESCE(SUM(DISTINCT jel.credit),0) - COALESCE(SUM(sja.amount),0) as available')
            )
            ->groupBy('je.id', 'je.journal_no', 'je.journal_date', 'je.memo')
            ->havingRaw('COALESCE(SUM(DISTINCT jel.credit),0) - COALESCE(SUM(sja.amount),0) > 0.0001')
            ->orderBy('je.journal_date')
            ->get();

        return ApiResponse::success($journals, 'Available journals retrieved');
    }

    /**
     * POST /purchases/supplier-payment-allocations
     *
     * Allocates a payment voucher against a supplier invoice or GRN.
     */
    public function allocatePayment(Request $request): JsonResponse
    {
        $request->validate([
            'payment_voucher_id' => 'required|integer|exists:payment_vouchers,id',
            'transaction_type'   => 'required|string|in:Supplier Invoice,GRN Receipt,Credit Note',
            'transaction_id'     => 'required|integer',
            'amount'             => 'required|numeric|min:0.0001',
        ]);

        // Check payment has sufficient balance
        $pv = DB::table('payment_vouchers')->find($request->payment_voucher_id);
        if (!$pv) return ApiResponse::notFound('Payment voucher not found');

        $allocated = DB::table('payment_voucher_allocations')
            ->where('payment_voucher_id', $request->payment_voucher_id)
            ->sum('this_allocation');

        $available = (float)$pv->amount - (float)$allocated;
        if ((float)$request->amount > $available + 0.0001) {
            return ApiResponse::error("Allocation amount exceeds available balance ({$available})", 422);
        }

        DB::table('payment_voucher_allocations')->insert([
            'payment_voucher_id' => $request->payment_voucher_id,
            'transaction_type'   => $request->transaction_type,
            'transaction_id'     => $request->transaction_id,
            'this_allocation'    => $request->amount,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return ApiResponse::created([], 'Payment allocated successfully');
    }

    /**
     * POST /purchases/supplier-journal-allocations
     *
     * Allocates a GL journal entry against a supplier invoice or GRN.
     */
    public function allocateJournal(Request $request): JsonResponse
    {
        $request->validate([
            'journal_id'       => 'required|integer|exists:journal_entries,id',
            'supplier_id'      => 'required|integer',
            'transaction_type' => 'required|string|in:Supplier Invoice,GRN Receipt',
            'transaction_id'   => 'required|integer',
            'amount'           => 'required|numeric|min:0.0001',
        ]);

        $je = DB::table('journal_entries')->find($request->journal_id);
        if (!$je || $je->status !== 'posted') {
            return ApiResponse::error('Journal must be posted to allocate', 422);
        }

        // Check journal has sufficient remaining balance for this supplier
        $totalCredit = DB::table('journal_entry_lines')
            ->where('journal_id', $request->journal_id)
            ->sum('credit');

        $allocated = DB::table('supplier_journal_allocations')
            ->where('journal_id', $request->journal_id)
            ->where('supplier_id', $request->supplier_id)
            ->sum('amount');

        $available = (float)$totalCredit - (float)$allocated;
        if ((float)$request->amount > $available + 0.0001) {
            return ApiResponse::error("Allocation amount exceeds available journal balance ({$available})", 422);
        }

        DB::table('supplier_journal_allocations')->insert([
            'journal_id'       => $request->journal_id,
            'transaction_type' => $request->transaction_type,
            'transaction_id'   => $request->transaction_id,
            'supplier_id'      => $request->supplier_id,
            'amount'           => $request->amount,
            'created_by'       => auth()->user()?->user_id ?? 'system',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return ApiResponse::created([], 'Journal allocated successfully');
    }

    /**
     * GET /purchases/invoice-allocations?transaction_id=&transaction_type=
     *
     * Returns all allocations (payments + journals) for a given invoice.
     */
    public function invoiceAllocations(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_id'   => 'required|integer',
            'transaction_type' => 'required|string',
        ]);

        $txId   = $request->transaction_id;
        $txType = $request->transaction_type;

        // Payment voucher allocations
        $pvAllocs = DB::table('payment_voucher_allocations as pva')
            ->join('payment_vouchers as pv', 'pv.id', '=', 'pva.payment_voucher_id')
            ->where('pva.transaction_id', $txId)
            ->where('pva.transaction_type', $txType)
            ->select(
                DB::raw("'payment' as source"),
                'pv.pvn_no as reference',
                'pv.date_paid as date',
                'pva.this_allocation as amount'
            )
            ->get();

        // Journal allocations
        $jAllocs = DB::table('supplier_journal_allocations as sja')
            ->join('journal_entries as je', 'je.id', '=', 'sja.journal_id')
            ->where('sja.transaction_id', $txId)
            ->where('sja.transaction_type', $txType)
            ->select(
                DB::raw("'journal' as source"),
                'je.journal_no as reference',
                'je.journal_date as date',
                'sja.amount'
            )
            ->get();

        $all = $pvAllocs->concat($jAllocs)->sortBy('date')->values();

        return ApiResponse::success([
            'allocations'    => $all,
            'total_allocated' => $all->sum('amount'),
        ], 'Invoice allocations retrieved');
    }
}
