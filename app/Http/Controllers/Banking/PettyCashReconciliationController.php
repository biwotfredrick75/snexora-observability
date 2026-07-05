<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PettyCashFund;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashVoucher;
use App\Services\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashReconciliationController extends Controller
{
    private const GL_TYPE = 60;

    // GET /banking/petty-cash/reconciliations
    public function index(Request $request): JsonResponse
    {
        $q = PettyCashReconciliation::with('fund:id,name,fund_code')
            ->orderByDesc('recon_date')->orderByDesc('id');

        if ($request->filled('fund_id')) $q->where('fund_id', $request->fund_id);
        if ($request->filled('status'))  $q->where('status', $request->status);

        return ApiResponse::success($q->limit(200)->get());
    }

    // POST /banking/petty-cash/reconciliations
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fund_id'           => 'required|exists:petty_cash_funds,id',
            'recon_date'        => 'required|date',
            'cash_counted'      => 'required|numeric|min:0',
            'is_surprise_audit' => 'nullable|boolean',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $fund = PettyCashFund::find($data['fund_id']);

        $vouchersTotal = PettyCashVoucher::where('fund_id', $fund->id)
            ->where('status', 'approved')
            ->where('replenished', false)
            ->sum('amount');

        $expectedBalance = $fund->imprest_amount - $vouchersTotal;
        $variance        = (float) $data['cash_counted'] - $expectedBalance;

        $userId = $request->user()?->user_id ?? 'system';

        $recon = PettyCashReconciliation::create([
            'recon_no'         => $this->nextReconNo(),
            'fund_id'          => $fund->id,
            'recon_date'       => $data['recon_date'],
            'expected_balance' => $expectedBalance,
            'vouchers_total'   => $vouchersTotal,
            'cash_counted'     => $data['cash_counted'],
            'variance'         => $variance,
            'is_surprise_audit'=> $data['is_surprise_audit'] ?? false,
            'status'           => 'draft',
            'created_by'       => $userId,
            'notes'            => $data['notes'] ?? null,
        ]);

        return ApiResponse::created($recon->load('fund:id,name,fund_code'), 'Reconciliation created');
    }

    // GET /banking/petty-cash/reconciliations/{id}
    public function show(int $id): JsonResponse
    {
        $recon = PettyCashReconciliation::with('fund')->find($id);
        if (! $recon) return ApiResponse::notFound('Reconciliation not found');

        $vouchers = PettyCashVoucher::where('fund_id', $recon->fund_id)
            ->where('status', 'approved')
            ->where('replenished', false)
            ->orderByDesc('voucher_date')
            ->get(['id', 'voucher_no', 'voucher_date', 'payee', 'description', 'amount', 'expense_account_code']);

        return ApiResponse::success([
            'reconciliation' => $recon,
            'vouchers'       => $vouchers,
        ]);
    }

    // POST /banking/petty-cash/reconciliations/{id}/finalize  (custodian sign-off)
    public function finalize(Request $request, int $id): JsonResponse
    {
        $recon = PettyCashReconciliation::with('fund')->find($id);
        if (! $recon) return ApiResponse::notFound('Reconciliation not found');
        if ($recon->status !== 'draft') {
            return ApiResponse::validationError(['status' => ['Reconciliation already '.$recon->status]]);
        }

        $userId = $request->user()?->user_id ?? 'system';

        DB::transaction(function () use ($recon, $userId) {
            $updates = [
                'status'              => 'custodian_signed',
                'custodian_signed_at' => now(),
            ];

            if (abs($recon->variance) > 0.001) {
                $transNo  = $this->nextTransNo();
                $date     = $recon->recon_date;
                $shortage = $recon->variance < 0;

                GlPostingService::post([
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => '5170',
                        'reference'    => $recon->recon_no,
                        'narration'    => ($shortage ? 'Cash shortage' : 'Cash overage').' on reconciliation '.$recon->recon_no,
                        'amount'       => $shortage ? abs($recon->variance) : -abs($recon->variance),
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => $recon->fund->gl_account_code,
                        'reference'    => $recon->recon_no,
                        'narration'    => ($shortage ? 'Cash shortage' : 'Cash overage').' on reconciliation '.$recon->recon_no,
                        'amount'       => $shortage ? -abs($recon->variance) : abs($recon->variance),
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                ]);

                $updates['variance_gl_trans_no'] = $transNo;

                if ($shortage) {
                    $recon->fund->decrement('current_balance', abs($recon->variance));
                } else {
                    $recon->fund->increment('current_balance', abs($recon->variance));
                }
            }

            $recon->update($updates);
        });

        return ApiResponse::success(null, 'Reconciliation signed off by custodian');
    }

    // POST /banking/petty-cash/reconciliations/{id}/countersign  (supervisor)
    public function countersign(Request $request, int $id): JsonResponse
    {
        $recon = PettyCashReconciliation::find($id);
        if (! $recon) return ApiResponse::notFound('Reconciliation not found');
        if ($recon->status !== 'custodian_signed') {
            return ApiResponse::validationError(['status' => ['Awaiting custodian sign-off first']]);
        }

        $userId = $request->user()?->user_id ?? 'system';

        $recon->update([
            'status'               => 'finalized',
            'supervisor_id'        => $userId,
            'supervisor_signed_at' => now(),
        ]);

        return ApiResponse::success(null, 'Reconciliation finalized');
    }

    private function nextReconNo(): string
    {
        $year = now()->year;
        $max  = DB::table('petty_cash_reconciliations')
            ->where('recon_no', 'like', "RCN%/{$year}")
            ->pluck('recon_no')
            ->map(fn ($n) => (int) substr($n, 3, max(0, strpos($n, '/') - 3)))
            ->max() ?? 0;
        return 'RCN'.str_pad($max + 1, 5, '0', STR_PAD_LEFT).'/'.$year;
    }

    private function nextTransNo(): int
    {
        return (int) (DB::table('gld_transactions')
            ->where('type', self::GL_TYPE)
            ->max('trans_no') ?? 0) + 1;
    }
}
