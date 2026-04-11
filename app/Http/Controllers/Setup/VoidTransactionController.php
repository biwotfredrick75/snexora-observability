<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoidTransactionController extends Controller
{
    /**
     * typeMap: type_slug => [
     *   table,
     *   num_col      (human-readable doc number column — may be string like "INV/001/2026"),
     *   date_col,
     *   ref_col      (optional extra reference, null to skip),
     *   statuses     (null = no status filter; array = exclude voided/void/cancelled),
     * ]
     *
     * Range search always uses `id` because doc numbers are formatted strings.
     * For the journals table there is no `id` — we use trans_no which IS numeric.
     */
    private function typeMap(): array
    {
        return [
            'journal_entry'           => ['journals',             'trans_no',  'tran_date',    'reference',   null],
            'sales_invoice'           => ['sales_invoices',       'inv_no',    'invoice_date', 'customer_ref', true],
            'customer_credit_note'    => ['credit_notes',         'cn_no',     'cn_date',      null,           true],
            'customer_payment'        => ['customer_payments',    'payment_no','payment_date', 'reference',    true],
            'delivery_note'           => ['sales_deliveries',     'dn_no',     'delivery_date','customer_ref', true],
            'location_transfer'       => ['inventory_transfers',  'reference', 'date',         null,           true],
            'inventory_adjustment'    => ['inventory_adjustments','id',        'date',         'reference',    true],
            'purchase_order'          => ['purchase_orders',      'po_no',     'order_date',   'reference',    true],
            'supplier_invoice'        => ['milk_supp_invoices',   'trans_no',  'tran_date',    'reference',    null],
            'supplier_credit_note'    => ['supplier_credit_notes','scn_no',    'date',         'reference',    true],
            'supplier_payment'        => ['payment_vouchers',     'pvn_no',    'date_paid',    'reference',    true],
            'work_order'              => ['work_orders',          'wo_no',     'start_date',   null,           true],
        ];
    }

    /**
     * GET /api/setup/void-transaction/search
     */
    public function search(Request $request): JsonResponse
    {
        $type = $request->input('type', 'sales_invoice');
        $from = (int) $request->input('from', 1);
        $to   = (int) $request->input('to', 999999);

        $map = $this->typeMap();

        if (! isset($map[$type])) {
            return ApiResponse::success([
                'type' => $type, 'from' => $from, 'to' => $to,
                'results' => [], 'note' => 'Type not supported yet',
            ], 'Search completed');
        }

        [$table, $numCol, $dateCol, $refCol, $hasStatus] = $map[$type];

        try {
            // journals uses trans_no as its numeric PK; everything else has an `id`
            $rangeCol = ($table === 'journals') ? 'trans_no' : 'id';

            $query = DB::table($table)->whereBetween($rangeCol, [$from, $to]);

            if ($hasStatus) {
                $query->whereNotIn('status', ['voided', 'void', 'cancelled', 'canceled']);
            }

            $rows = $query->orderBy($rangeCol)->limit(500)->get();

            $results = $rows->map(function ($row) use ($numCol, $dateCol, $refCol, $rangeCol) {
                $row   = (array) $row;
                $docNo = $row[$numCol] ?? ($row['id'] ?? null);
                $ref   = $refCol && isset($row[$refCol]) ? $row[$refCol] : null;
                $date  = isset($row[$dateCol]) ? $row[$dateCol] : null;

                return [
                    'id'        => $row['id'] ?? $row[$rangeCol] ?? null,
                    'number'    => $docNo,
                    'reference' => $ref ?: $docNo,
                    'date'      => $date ? \Carbon\Carbon::parse($date)->format('Y-m-d') : null,
                    'status'    => $row['status'] ?? null,
                ];
            })->values();

            return ApiResponse::success([
                'type'    => $type,
                'from'    => $from,
                'to'      => $to,
                'results' => $results,
            ], 'Search completed');

        } catch (\Throwable $e) {
            return ApiResponse::validationError(['search' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/setup/void-transaction/void
     */
    public function void(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'           => 'required|string',
            'transaction_id' => 'required',
            'void_date'      => 'required|date',
            'reason'         => 'nullable|string|max:500',
        ]);

        $type = $validated['type'];
        $id   = $validated['transaction_id'];
        $map  = $this->typeMap();

        if (! isset($map[$type])) {
            return ApiResponse::validationError(['type' => 'Transaction type not supported.']);
        }

        [$table, $numCol, $dateCol, $refCol, $hasStatus] = $map[$type];

        try {
            $idCol = ($table === 'journals') ? 'trans_no' : 'id';
            $row   = DB::table($table)->where($idCol, $id)->first();

            if (! $row) {
                return ApiResponse::notFound('Transaction not found.');
            }

            $row = (array) $row;

            if ($hasStatus && isset($row['status'])) {
                if (in_array($row['status'], ['voided', 'void', 'cancelled', 'canceled'])) {
                    return ApiResponse::validationError(['status' => 'This transaction is already voided.']);
                }

                DB::table($table)
                    ->where($idCol, $id)
                    ->update(['status' => 'voided', 'updated_at' => now()]);
            }

            // Reverse any GL entries
            $docNo = $row[$numCol] ?? $id;
            $this->reverseGlEntries($type, $docNo, $validated['void_date'], $validated['reason'] ?? '');

            return ApiResponse::success(null, 'Transaction voided successfully');

        } catch (\Throwable $e) {
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }

    /**
     * Creates reversing GL entries for the voided transaction.
     */
    private function reverseGlEntries(string $type, $transNo, string $voidDate, string $reason): void
    {
        $typeCode = $this->glTypeCode($type);
        if ($typeCode === null) return;

        $origLines = DB::table('gld_transactions')
            ->where('type', $typeCode)
            ->where('trans_no', $transNo)
            ->get();

        if ($origLines->isEmpty()) return;

        $nextNo = (DB::table('gld_transactions')->max('trans_no') ?? 0) + 1;

        $inserts = $origLines->map(fn ($l) => [
            'type'           => 0,
            'trans_no'       => $nextNo,
            'tran_date'      => $voidDate,
            'account_code'   => $l->account_code,
            'dimension_id'   => $l->dimension_id ?? null,
            'amount'         => -$l->amount,
            'memo'           => 'VOID ' . strtoupper($type) . ' #' . $transNo . ($reason ? " – {$reason}" : ''),
            'person_type_id' => $l->person_type_id ?? null,
            'person_id'      => $l->person_id ?? null,
            'reference'      => 'VOID-' . $transNo,
        ])->toArray();

        DB::table('gld_transactions')->insert($inserts);
    }

    private function glTypeCode(string $type): ?int
    {
        return match ($type) {
            'journal_entry'        => 0,
            'sales_invoice'        => 10,
            'customer_credit_note' => 11,
            'customer_payment'     => 12,
            'delivery_note'        => 13,
            'supplier_invoice'     => 20,
            'supplier_credit_note' => 21,
            'supplier_payment'     => 22,
            'purchase_order'       => 25,
            'inventory_adjustment' => 35,
            'location_transfer'    => 32,
            'work_order'           => 40,
            default                => null,
        };
    }
}
