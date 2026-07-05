<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashFundController extends Controller
{
    // GET /banking/petty-cash/funds
    public function index(): JsonResponse
    {
        $funds = PettyCashFund::orderBy('fund_code')->get()->map(function ($f) {
            $pending = PettyCashVoucher::where('fund_id', $f->id)
                ->whereIn('status', ['pending'])->count();
            return array_merge($f->toArray(), [
                'pending_vouchers' => $pending,
                'low_balance'      => $f->isLowBalance(),
            ]);
        });

        return ApiResponse::success($funds, 'Petty cash funds retrieved');
    }

    // GET /banking/petty-cash/funds/{id}
    public function show(int $id): JsonResponse
    {
        $fund = PettyCashFund::find($id);
        if (! $fund) return ApiResponse::notFound('Fund not found');

        $recentVouchers = PettyCashVoucher::where('fund_id', $id)
            ->orderByDesc('voucher_date')->orderByDesc('id')
            ->limit(20)->get();

        return ApiResponse::success([
            'fund'            => $fund,
            'recent_vouchers' => $recentVouchers,
            'low_balance'     => $fund->isLowBalance(),
        ]);
    }

    // POST /banking/petty-cash/funds
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fund_code'                  => 'required|string|max:20|unique:petty_cash_funds,fund_code',
            'name'                       => 'required|string|max:100',
            'description'                => 'nullable|string|max:255',
            'gl_account_code'            => 'required|string|max:20',
            'imprest_amount'             => 'required|numeric|min:1',
            'transaction_limit'          => 'nullable|numeric|min:1',
            'low_balance_pct'            => 'nullable|integer|min:5|max:50',
            'custodian_user_id'          => 'nullable|string|max:60',
            'backup_custodian_user_id'   => 'nullable|string|max:60',
            'cost_center'                => 'nullable|string|max:100',
            'currency'                   => 'nullable|string|max:10',
        ]);

        $data['current_balance'] = $data['imprest_amount'];
        $data['status']          = 'active';

        $fund = PettyCashFund::create($data);

        return ApiResponse::created($fund, 'Petty cash fund created');
    }

    // PUT /banking/petty-cash/funds/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $fund = PettyCashFund::find($id);
        if (! $fund) return ApiResponse::notFound('Fund not found');

        $data = $request->validate([
            'name'                       => 'sometimes|string|max:100',
            'description'                => 'nullable|string|max:255',
            'gl_account_code'            => 'sometimes|string|max:20',
            'imprest_amount'             => 'sometimes|numeric|min:1',
            'transaction_limit'          => 'nullable|numeric|min:1',
            'low_balance_pct'            => 'nullable|integer|min:5|max:50',
            'custodian_user_id'          => 'nullable|string|max:60',
            'backup_custodian_user_id'   => 'nullable|string|max:60',
            'cost_center'                => 'nullable|string|max:100',
            'status'                     => 'nullable|in:active,suspended,closed',
        ]);

        $fund->update($data);

        return ApiResponse::updated($fund, 'Fund updated');
    }

    // GET /banking/petty-cash/dashboard
    public function dashboard(): JsonResponse
    {
        $funds = PettyCashFund::where('status', 'active')->get();

        $totalBalance    = $funds->sum('current_balance');
        $totalImprest    = $funds->sum('imprest_amount');
        $pendingVouchers = PettyCashVoucher::whereIn('status', ['pending'])->count();
        $approvedVouchers = PettyCashVoucher::where('status', 'approved')
            ->where('replenished', false)->count();

        $recentVouchers = PettyCashVoucher::with('fund:id,name,fund_code')
            ->orderByDesc('voucher_date')->orderByDesc('id')
            ->limit(10)->get();

        $fundCards = $funds->map(fn ($f) => [
            'id'              => $f->id,
            'fund_code'       => $f->fund_code,
            'name'            => $f->name,
            'imprest_amount'  => $f->imprest_amount,
            'current_balance' => $f->current_balance,
            'balance_pct'     => $f->imprest_amount > 0
                ? round($f->current_balance / $f->imprest_amount * 100, 1)
                : 0,
            'low_balance'     => $f->isLowBalance(),
            'currency'        => $f->currency,
        ]);

        return ApiResponse::success(compact(
            'totalBalance', 'totalImprest', 'pendingVouchers', 'approvedVouchers',
            'fundCards', 'recentVouchers'
        ), 'Dashboard loaded');
    }

    // GET /banking/petty-cash/form-data (enhanced — includes funds + GL accounts)
    public function formData(): JsonResponse
    {
        $glAccounts = DB::table('gl_accounts')
            ->where('inactive', false)
            ->orderBy('code')
            ->get(['code', 'name']);

        $pettyCashAccounts = DB::table('gl_accounts')
            ->join('gl_account_groups', 'gl_accounts.group_id', '=', 'gl_account_groups.id')
            ->where('gl_account_groups.code', '1021')
            ->where('gl_accounts.inactive', false)
            ->orderBy('gl_accounts.code')
            ->get(['gl_accounts.code', 'gl_accounts.name']);

        $users = DB::table('users')
            ->where('inactive', false)
            ->orderBy('real_name')
            ->get(['user_id', 'real_name']);

        $funds = PettyCashFund::where('status', 'active')
            ->orderBy('fund_code')
            ->get(['id', 'fund_code', 'name', 'gl_account_code', 'current_balance',
                   'imprest_amount', 'transaction_limit', 'currency']);

        return ApiResponse::success(compact(
            'glAccounts', 'pettyCashAccounts', 'users', 'funds'
        ));
    }
}
