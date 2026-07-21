<?php

namespace App\Http\Controllers\Sales;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\DebitNoteItem;
use App\Models\DebitNoteReason;
use App\Models\GldTransaction;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Debit Note — the mirror of Credit Notes: increases what a
 * customer owes (an undercharge correction or a supplementary charge)
 * instead of decreasing it. Same draft -> place lifecycle and GL
 * convention as Sales\CreditNoteController, with the DR/CR legs flipped.
 */
class DebitNoteController extends Controller
{
    public function nextRef(): JsonResponse
    {
        $ref = DB::transaction(fn () => DebitNote::nextDnNo());
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'reasons'    => DebitNoteReason::orderBy('reason_name')->get(),
            'tax_types'  => DB::table('tax_types')->select('id', 'description', 'default_rate')->get(),
            'locations'  => DB::table('inventory_locations')->where('inactive', 0)->select('id', 'code', 'name')->get(),
        ], 'Debit note form data retrieved');
    }

    public function index(Request $request): JsonResponse
    {
        $query = DebitNote::with(['customer:debtor_no,name', 'reason:id,reason_name'])
            ->orderByDesc('id');

        if ($v = $request->get('debtor_no')) $query->where('debtor_no', $v);
        if ($v = $request->get('status'))    $query->where('status', $v);
        if ($v = $request->get('date_from')) $query->where('dn_date', '>=', $v);
        if ($v = $request->get('date_to'))   $query->where('dn_date', '<=', $v);

        $notes = $query->paginate(min((int) $request->get('per_page', 20), 100));

        return ApiResponse::paginated($notes, 'Debit notes retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $dn = DebitNote::with(['items', 'customer', 'reason', 'invoice'])->find($id);
        if (! $dn) return ApiResponse::notFound('Debit note not found');
        return ApiResponse::success($dn, 'Debit note retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'      => 'required|string|max:10|exists:customers,debtor_no',
            'inv_id'         => 'nullable|integer|exists:sales_invoices,id',
            'dn_date'        => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'reason_id'      => 'nullable|integer|exists:debit_note_reasons,id',
            'location_id'    => 'nullable|integer',
            'tax_type_id'    => 'nullable|integer|exists:tax_types,id',
            'dimension_id'   => 'nullable|integer',
            'dimension2_id'  => 'nullable|integer',
            'comments'       => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.stock_id'     => 'nullable|string|max:20',
            'items.*.description'  => 'required|string|max:200',
            'items.*.qty'          => 'required|numeric|min:0.0001',
            'items.*.price'        => 'required|numeric|min:0',
            'items.*.unit'         => 'nullable|string|max:20',
        ]);

        return DB::transaction(function () use ($validated) {
            $subTotal = 0;
            $lines = array_map(function ($item) use (&$subTotal) {
                $lineTotal = round($item['qty'] * $item['price'], 2);
                $subTotal += $lineTotal;
                return [...$item, 'line_total' => $lineTotal];
            }, $validated['items']);

            $taxRate = !empty($validated['tax_type_id'])
                ? (float) (DB::table('tax_types')->where('id', $validated['tax_type_id'])->value('default_rate') ?? 0)
                : 0;
            $taxAmount = round($subTotal * $taxRate / 100, 2);

            $dn = DebitNote::create([
                'dn_no'         => 'TEMP-' . uniqid(),
                'inv_id'        => $validated['inv_id'] ?? null,
                'debtor_no'     => $validated['debtor_no'],
                'dn_date'       => $validated['dn_date'],
                'reason_id'     => $validated['reason_id'] ?? null,
                'location_id'   => $validated['location_id'] ?? null,
                'tax_type_id'   => $validated['tax_type_id'] ?? null,
                'dimension_id'  => $validated['dimension_id'] ?? null,
                'dimension2_id' => $validated['dimension2_id'] ?? null,
                'sub_total'     => round($subTotal, 2),
                'tax_amount'    => $taxAmount,
                'amount_total'  => round($subTotal + $taxAmount, 2),
                'unallocated_amount' => round($subTotal + $taxAmount, 2),
                'status'        => 'draft',
                'comments'      => $validated['comments'] ?? null,
                'created_by'    => auth()->user()?->user_id ?? 'system',
            ]);
            $dn->update(['dn_no' => DebitNote::nextDnNo()]);

            foreach ($lines as $line) {
                DebitNoteItem::create([
                    'dn_id'       => $dn->id,
                    'stock_id'    => $line['stock_id'] ?? null,
                    'description' => $line['description'],
                    'qty'         => $line['qty'],
                    'unit'        => $line['unit'] ?? null,
                    'price'       => $line['price'],
                    'line_total'  => $line['line_total'],
                ]);
            }

            return ApiResponse::created($dn->load('items'), 'Debit note created');
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dn = DebitNote::find($id);
        if (! $dn) return ApiResponse::notFound('Debit note not found');
        if ($dn->status !== 'draft') return ApiResponse::error('Only draft debit notes can be edited', 422);

        $validated = $request->validate([
            'dn_date'       => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'reason_id'     => 'nullable|integer|exists:debit_note_reasons,id',
            'tax_type_id'   => 'nullable|integer|exists:tax_types,id',
            'comments'      => 'nullable|string',
            'items'                => 'sometimes|array|min:1',
            'items.*.stock_id'     => 'nullable|string|max:20',
            'items.*.description'  => 'required_with:items|string|max:200',
            'items.*.qty'          => 'required_with:items|numeric|min:0.0001',
            'items.*.price'        => 'required_with:items|numeric|min:0',
            'items.*.unit'         => 'nullable|string|max:20',
        ]);

        return DB::transaction(function () use ($validated, $dn) {
            $dn->fill(array_diff_key($validated, ['items' => null]));

            if (isset($validated['items'])) {
                $dn->items()->delete();
                $subTotal = 0;
                foreach ($validated['items'] as $item) {
                    $lineTotal = round($item['qty'] * $item['price'], 2);
                    $subTotal += $lineTotal;
                    DebitNoteItem::create([
                        'dn_id'       => $dn->id,
                        'stock_id'    => $item['stock_id'] ?? null,
                        'description' => $item['description'],
                        'qty'         => $item['qty'],
                        'unit'        => $item['unit'] ?? null,
                        'price'       => $item['price'],
                        'line_total'  => $lineTotal,
                    ]);
                }
                $taxRate = $dn->tax_type_id
                    ? (float) (DB::table('tax_types')->where('id', $dn->tax_type_id)->value('default_rate') ?? 0)
                    : 0;
                $dn->tax_amount    = round($subTotal * $taxRate / 100, 2);
                $dn->sub_total     = round($subTotal, 2);
                $dn->amount_total  = round($subTotal + $dn->tax_amount, 2);
                $dn->unallocated_amount = $dn->amount_total;
            }

            $dn->save();

            return ApiResponse::updated($dn->fresh()->load('items'), 'Debit note updated');
        });
    }

    public function place(int $id): JsonResponse
    {
        $dn = DebitNote::with(['items', 'reason'])->find($id);
        if (! $dn) return ApiResponse::notFound('Debit note not found');
        if ($dn->status !== 'draft') return ApiResponse::error('Debit note is not in draft status', 422);

        $company   = DB::table('company_preferences')->first();
        $glSetting = DB::table('gl_settings')->first();

        $placed = DB::transaction(function () use ($dn, $company, $glSetting) {
            $dn->update(['status' => 'placed']);

            $createdBy = auth()->user()?->user_id ?? 'system';
            $dnNo      = $dn->dn_no;
            $tranDate  = $dn->dn_date instanceof \Carbon\Carbon ? $dn->dn_date->toDateString() : $dn->dn_date;

            $incomeAccount = $dn->reason?->gl_account
                ?: ($glSetting->sales_account ?? null)
                ?: 'SALES_REVENUE';
            $debtorsGlCode = ($company->debtors_gl_code ?? null) ?: 'DEBTORS';

            // DR Debtors — the customer now owes more
            $this->glEntry($dn->id, $tranDate, $debtorsGlCode, $dn->amount_total,
                "Debit Note — {$dnNo}", $dnNo, $createdBy, $dn->dimension_id, $dn->dimension2_id);

            // CR Income account for the reason
            $this->glEntry($dn->id, $tranDate, $incomeAccount, -$dn->sub_total,
                "Debit Note — {$dn->reason?->reason_name} ({$dnNo})", $dnNo, $createdBy, $dn->dimension_id, $dn->dimension2_id);

            // CR Tax Payable — always post this leg once tax_amount > 0, otherwise
            // the DR Debtors (which includes tax) would not balance against the
            // credits (which would only cover sub_total).
            if ($dn->tax_amount > 0) {
                $taxAccount = ($dn->tax_type_id ? DB::table('tax_types')->where('id', $dn->tax_type_id)->value('sales_gl_account') : null)
                    ?: 'TAX_PAYABLE';
                $this->glEntry($dn->id, $tranDate, $taxAccount, -$dn->tax_amount,
                    "Debit Note Tax — {$dnNo}", $dnNo, $createdBy, $dn->dimension_id, $dn->dimension2_id);
            }

            return $dn->fresh(['items', 'customer', 'reason']);
        });

        try {
            broadcast(new DashboardEvent('debit_note', 'placed', ['dn_no' => $dn->dn_no, 'amount' => $dn->amount_total]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }

        return ApiResponse::success($placed, 'Debit note placed');
    }

    public function cancel(int $id): JsonResponse
    {
        $dn = DebitNote::find($id);
        if (! $dn) return ApiResponse::notFound('Debit note not found');
        if ($dn->status !== 'draft') {
            return ApiResponse::error('Only draft debit notes can be cancelled — a placed debit note must be reversed with a credit note', 422);
        }
        $dn->update(['status' => 'cancelled']);
        return ApiResponse::updated($dn->fresh(), 'Debit note cancelled');
    }

    private function glEntry(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy, ?int $dimId = null, ?int $dim2Id = null): void
    {
        GldTransaction::create([
            'trans_no'      => $transNo,
            'type'          => StockMovement::TYPE_DEBIT_NOTE,
            'tran_date'     => $tranDate,
            'account_code'  => $accountCode,
            'reference'     => $reference,
            'narration'     => $narration,
            'amount'        => $amount,
            'created_by'    => $createdBy,
            'dimension_id'  => $dimId,
            'dimension2_id' => $dim2Id,
        ]);
    }
}
