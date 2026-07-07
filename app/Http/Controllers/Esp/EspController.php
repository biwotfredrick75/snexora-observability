<?php

namespace App\Http\Controllers\Esp;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\EspProvider;
use App\Models\EspSale;
use App\Models\EspSaleAdjustment;
use App\Models\EspSaleItem;
use App\Models\EspCompanyPurchase;
use App\Models\EspCompanyPurchaseItem;
use App\Models\EspSettlement;
use App\Models\Farmer;
use App\Services\Esp\PartyCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EspController extends Controller
{
    // ── Reference number helper ───────────────────────────────────────────────
    private function nextRef(string $prefix, string $table, string $col): string
    {
        $year = date('Y');
        $last = DB::table($table)->lockForUpdate()->max('id') ?? 0;
        return $prefix . '-' . $year . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public function nextCode(): JsonResponse
    {
        // Find the highest numeric suffix from existing codes like ESP001, ESP002…
        $last = EspProvider::selectRaw("MAX(CAST(SUBSTRING(esp_code, 4) AS UNSIGNED)) AS n")
            ->whereRaw("esp_code REGEXP '^ESP[0-9]+$'")
            ->value('n') ?? 0;

        return ApiResponse::success(['code' => 'ESP' . str_pad($last + 1, 3, '0', STR_PAD_LEFT)]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PROVIDERS
    // ════════════════════════════════════════════════════════════════════════

    public function indexProviders(Request $request): JsonResponse
    {
        $q = EspProvider::query();
        if ($request->status) $q->where('status', $request->status);
        if ($request->search) $q->where(fn($w) => $w->where('name', 'like', "%{$request->search}%")->orWhere('esp_code', 'like', "%{$request->search}%"));
        return ApiResponse::success($q->orderBy('name')->get());
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esp_code'        => 'required|string|max:20|unique:esp_providers,esp_code',
            'name'            => 'required|string|max:150',
            'contact_person'  => 'nullable|string|max:100',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:100',
            'address'         => 'nullable|string|max:255',
            'credit_limit_pct'=> 'nullable|numeric|min:1|max:100',
            'notes'           => 'nullable|string',
        ]);
        $data['created_by'] = auth()->id();
        $provider = EspProvider::create($data);
        return ApiResponse::created($provider, 'Provider created');
    }

    public function showProvider(EspProvider $provider): JsonResponse
    {
        $provider->load(['sales', 'companyPurchases', 'settlements']);

        // Compute summary balances
        $provider->farmer_sales_outstanding  = $provider->sales->where('status', '!=', 'settled')->sum('balance');
        $provider->company_purchases_outstanding = $provider->companyPurchases->where('status', '!=', 'settled')->sum('balance');
        $provider->net_position = $provider->farmer_sales_outstanding - $provider->company_purchases_outstanding;

        return ApiResponse::success($provider);
    }

    public function updateProvider(Request $request, EspProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'sometimes|string|max:150',
            'contact_person'  => 'nullable|string|max:100',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:100',
            'address'         => 'nullable|string|max:255',
            'credit_limit_pct'=> 'nullable|numeric|min:1|max:100',
            'status'          => 'sometimes|in:active,inactive',
            'notes'           => 'nullable|string',
        ]);
        $provider->update($data);
        return ApiResponse::updated($provider, 'Provider updated');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  FARMER CREDIT SCORE
    // ════════════════════════════════════════════════════════════════════════

    public function farmerCredit(Request $request, int $farmerId): JsonResponse
    {
        $farmer = Farmer::findOrFail($farmerId);

        // Average monthly milk payment over last 3 months
        $from = now()->subMonths(3)->startOfMonth()->toDateString();
        $avgMonthly = DB::table('farmer_payments')
            ->where('farmer_id', $farmerId)
            ->where('date_paid', '>=', $from)
            ->avg('amount_payment') ?? 0;

        $espId = $request->esp_id;
        $creditLimitPct = 50.0;
        if ($espId) {
            $esp = EspProvider::find($espId);
            if ($esp) $creditLimitPct = (float) $esp->credit_limit_pct;
        }

        $creditLimit = round((float) $avgMonthly * ($creditLimitPct / 100), 2);

        // Outstanding ESP balance for this farmer
        $outstanding = EspSale::where('farmer_id', $farmerId)
            ->whereIn('status', ['pending', 'partial'])
            ->sum('balance');

        $availableCredit = max(0, $creditLimit - $outstanding);

        return ApiResponse::success([
            'farmer_id'       => $farmerId,
            'farmer_no'       => $farmer->farmer_no,
            'farmer_name'     => $farmer->full_name,
            'avg_monthly_pay' => round((float) $avgMonthly, 2),
            'credit_limit_pct'=> $creditLimitPct,
            'credit_limit'    => $creditLimit,
            'outstanding'     => round((float) $outstanding, 2),
            'available_credit'=> $availableCredit,
            'credit_score'    => $creditLimit > 0
                ? round((1 - min(1, $outstanding / $creditLimit)) * 100, 1)
                : 0,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  FARMER SALES
    // ════════════════════════════════════════════════════════════════════════

    public function indexFarmerSales(Request $request): JsonResponse
    {
        $q = EspSale::with(['esp:id,name,esp_code', 'farmer:id,farmer_no,full_name']);
        if ($request->esp_id)   $q->where('esp_id', $request->esp_id);
        if ($request->farmer_id)$q->where('farmer_id', $request->farmer_id);
        if ($request->status)   $q->where('status', $request->status);
        if ($request->from)     $q->where('sale_date', '>=', $request->from);
        if ($request->to)       $q->where('sale_date', '<=', $request->to);
        return ApiResponse::success($q->orderByDesc('id')->limit(500)->get());
    }

    public function storeFarmerSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esp_id'        => 'required|exists:esp_providers,id',
            'farmer_id'     => 'required|exists:farmers,id',
            'sale_date'     => 'required|date',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty'         => 'required|numeric|min:0.001',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $total = collect($data['items'])->sum(fn($i) => $i['qty'] * $i['unit_price']);

        return DB::transaction(function () use ($data, $total, $request) {
            $sale = EspSale::create([
                'sale_no'         => $this->nextRef('ESPS', 'esp_farmer_sales', 'sale_no'),
                'esp_id'          => $data['esp_id'],
                'party_type'      => 'farmer',
                'party_id'        => $data['farmer_id'],
                'farmer_id'       => $data['farmer_id'],
                'sale_date'       => $data['sale_date'],
                'total_amount'    => $total,
                'deducted_amount' => 0,
                'balance'         => $total,
                'status'          => 'pending',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                EspSaleItem::create([
                    'sale_id'     => $sale->id,
                    'description' => $item['description'],
                    'qty'         => $item['qty'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => round($item['qty'] * $item['unit_price'], 2),
                ]);
            }

            return ApiResponse::created($sale->load('items'), 'Farmer sale recorded');
        });
    }

    public function showFarmerSale(EspSale $sale): JsonResponse
    {
        return ApiResponse::success($sale->load(['esp', 'farmer', 'items']));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  MULTI-PARTY SALES (mobile app — farmers, employees, transporters/graders)
    // ════════════════════════════════════════════════════════════════════════

    /** GET /esp/credit-score?party_type=&party_id= — used by the app before checkout */
    public function creditScore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'party_type' => 'required|in:farmer,employee,transporter',
            'party_id'   => 'required|integer|min:1',
        ]);

        try {
            $score = app(PartyCreditService::class)->score($data['party_type'], (int) $data['party_id']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::notFound($e->getMessage());
        }

        return ApiResponse::success(array_merge(['party_type' => $data['party_type'], 'party_id' => (int) $data['party_id']], $score));
    }

    /** GET /esp/parties?party_type=employee|transporter&search= — lookup lists the app needs */
    public function indexParties(Request $request): JsonResponse
    {
        $data = $request->validate([
            'party_type' => 'required|in:employee,transporter',
            'search'     => 'nullable|string|max:100',
        ]);

        if ($data['party_type'] === 'employee') {
            $q = Employee::query()->where('status', 'active');
            if (! empty($data['search'])) {
                $s = $data['search'];
                $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('emp_no', 'like', "%{$s}%"));
            }
            return ApiResponse::success($q->orderBy('full_name')->limit(50)->get(['id', 'emp_no', 'full_name', 'basic_salary']));
        }

        $q = DB::table('inventory_locations')->whereIn('type', ['grader', 'vendor'])->where('inactive', false);
        if (! empty($data['search'])) {
            $s = $data['search'];
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }
        return ApiResponse::success($q->orderBy('name')->limit(50)->get(['id', 'code', 'name']));
    }

    /** POST /esp/sales — generalized create for any party type, server-enforced credit limit */
    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esp_id'        => 'required|exists:esp_providers,id',
            'party_type'    => 'required|in:farmer,employee,transporter',
            'party_id'      => 'required|integer|min:1',
            'sale_date'     => 'required|date',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty'         => 'required|numeric|min:0.001',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $total = collect($data['items'])->sum(fn ($i) => $i['qty'] * $i['unit_price']);

        try {
            $score = app(PartyCreditService::class)->score($data['party_type'], (int) $data['party_id']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::notFound($e->getMessage());
        }

        if ($total > $score['available_credit']) {
            return ApiResponse::error(
                sprintf(
                    'Sale of %.2f exceeds available credit of %.2f for this %s.',
                    $total, $score['available_credit'], $data['party_type']
                ),
                422
            );
        }

        return DB::transaction(function () use ($data, $total) {
            $sale = EspSale::create([
                'sale_no'         => $this->nextRef('ESPS', 'esp_farmer_sales', 'sale_no'),
                'esp_id'          => $data['esp_id'],
                'party_type'      => $data['party_type'],
                'party_id'        => $data['party_id'],
                'farmer_id'       => $data['party_type'] === 'farmer' ? $data['party_id'] : null,
                'sale_date'       => $data['sale_date'],
                'total_amount'    => $total,
                'deducted_amount' => 0,
                'balance'         => $total,
                'status'          => 'pending',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                EspSaleItem::create([
                    'sale_id'     => $sale->id,
                    'description' => $item['description'],
                    'qty'         => $item['qty'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => round($item['qty'] * $item['unit_price'], 2),
                ]);
            }

            return ApiResponse::created($sale->load('items'), 'Sale recorded');
        });
    }

    /** GET /esp/sales?party_type=&party_id=&esp_id=&status= */
    public function indexSales(Request $request): JsonResponse
    {
        $q = EspSale::with(['esp:id,name,esp_code']);
        if ($request->esp_id)     $q->where('esp_id', $request->esp_id);
        if ($request->party_type) $q->where('party_type', $request->party_type);
        if ($request->party_id)   $q->where('party_id', $request->party_id);
        if ($request->status)     $q->where('status', $request->status);
        return ApiResponse::success($q->orderByDesc('id')->limit(200)->get());
    }

    public function showSale(EspSale $sale): JsonResponse
    {
        return ApiResponse::success($sale->load(['esp', 'items', 'adjustments']));
    }

    /** PUT /esp/sales/{id} — edit line items while still pending & not yet deducted */
    public function updateSale(Request $request, EspSale $sale): JsonResponse
    {
        if ($sale->party_deducted) {
            return ApiResponse::error('Cannot edit — already deducted from the party\'s pay. Raise an adjustment instead.', 422);
        }
        if ($sale->status === 'void') {
            return ApiResponse::error('Cannot edit a voided sale', 422);
        }

        $data = $request->validate([
            'sale_date' => 'sometimes|date',
            'notes'     => 'nullable|string',
            'items'     => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty'         => 'required|numeric|min:0.001',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $total = collect($data['items'])->sum(fn ($i) => $i['qty'] * $i['unit_price']);

        try {
            $score = app(PartyCreditService::class)->score($sale->party_type, (int) $sale->party_id);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::notFound($e->getMessage());
        }
        // This sale's own current total is already excluded from "already_invoiced"
        // only once we remove it below — check against credit room excluding itself.
        $availableExcludingThis = $score['available_credit'] + (float) $sale->total_amount;
        if ($total > $availableExcludingThis) {
            return ApiResponse::error(
                sprintf('Updated total %.2f exceeds available credit of %.2f.', $total, $availableExcludingThis),
                422
            );
        }

        return DB::transaction(function () use ($sale, $data, $total) {
            $sale->items()->delete();
            foreach ($data['items'] as $item) {
                EspSaleItem::create([
                    'sale_id'     => $sale->id,
                    'description' => $item['description'],
                    'qty'         => $item['qty'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => round($item['qty'] * $item['unit_price'], 2),
                ]);
            }

            $sale->update([
                'sale_date'    => $data['sale_date'] ?? $sale->sale_date,
                'notes'        => $data['notes'] ?? $sale->notes,
                'total_amount' => $total,
                'balance'      => $total - (float) $sale->deducted_amount,
            ]);

            return ApiResponse::updated($sale->load('items'), 'Sale updated');
        });
    }

    /** POST /esp/sales/{id}/void — void a pending, undeducted sale */
    public function voidSale(EspSale $sale): JsonResponse
    {
        if ($sale->party_deducted) {
            return ApiResponse::error('Cannot void — already deducted from the party\'s pay. Raise an adjustment instead.', 422);
        }

        $sale->update(['status' => 'void', 'balance' => 0]);
        return ApiResponse::success($sale, 'Sale voided');
    }

    /** POST /esp/sales/{id}/adjust — append-only correction after the fact */
    public function adjustSale(Request $request, EspSale $sale): JsonResponse
    {
        if ($sale->status === 'void') {
            return ApiResponse::error('Cannot adjust a voided sale', 422);
        }

        $data = $request->validate([
            'delta_amount' => 'required|numeric|not_in:0',
            'reason'       => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($sale, $data) {
            EspSaleAdjustment::create([
                'sale_id'      => $sale->id,
                'delta_amount' => $data['delta_amount'],
                'reason'       => $data['reason'],
                'created_by'   => auth()->id(),
            ]);

            $sale->total_amount += $data['delta_amount'];
            $sale->balance      += $data['delta_amount'];
            $sale->save();

            return ApiResponse::created($sale->load(['items', 'adjustments']), 'Adjustment recorded');
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    //  COMPANY PURCHASES
    // ════════════════════════════════════════════════════════════════════════

    public function indexCompanyPurchases(Request $request): JsonResponse
    {
        $q = EspCompanyPurchase::with(['esp:id,name,esp_code']);
        if ($request->esp_id) $q->where('esp_id', $request->esp_id);
        if ($request->status) $q->where('status', $request->status);
        if ($request->from)   $q->where('purchase_date', '>=', $request->from);
        if ($request->to)     $q->where('purchase_date', '<=', $request->to);
        return ApiResponse::success($q->orderByDesc('id')->limit(500)->get());
    }

    public function storeCompanyPurchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esp_id'        => 'required|exists:esp_providers,id',
            'purchase_date' => 'required|date',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty'         => 'required|numeric|min:0.001',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $total = collect($data['items'])->sum(fn($i) => $i['qty'] * $i['unit_price']);

        return DB::transaction(function () use ($data, $total) {
            $purchase = EspCompanyPurchase::create([
                'purchase_no'     => $this->nextRef('ESPP', 'esp_company_purchases', 'purchase_no'),
                'esp_id'          => $data['esp_id'],
                'purchase_date'   => $data['purchase_date'],
                'total_amount'    => $total,
                'deducted_amount' => 0,
                'balance'         => $total,
                'status'          => 'pending',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                EspCompanyPurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'description' => $item['description'],
                    'qty'         => $item['qty'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => round($item['qty'] * $item['unit_price'], 2),
                ]);
            }

            return ApiResponse::created($purchase->load('items'), 'Company purchase recorded');
        });
    }

    public function showCompanyPurchase(EspCompanyPurchase $purchase): JsonResponse
    {
        return ApiResponse::success($purchase->load(['esp', 'items']));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SETTLEMENTS
    // ════════════════════════════════════════════════════════════════════════

    public function indexSettlements(Request $request): JsonResponse
    {
        $q = EspSettlement::with(['esp:id,name,esp_code']);
        if ($request->esp_id) $q->where('esp_id', $request->esp_id);
        if ($request->status) $q->where('status', $request->status);
        return ApiResponse::success($q->orderByDesc('id')->limit(300)->get());
    }

    /** Compute a proposed settlement — preview before posting */
    public function previewSettlement(Request $request): JsonResponse
    {
        $request->validate([
            'esp_id'      => 'required|exists:esp_providers,id',
            'period_from' => 'required|date',
            'period_to'   => 'required|date|after_or_equal:period_from',
        ]);

        $espId = $request->esp_id;
        $from  = $request->period_from;
        $to    = $request->period_to;

        // Farmer sales that have deductions in this period (all outstanding)
        $farmerSales = EspSale::where('esp_id', $espId)
            ->whereIn('status', ['pending', 'partial'])
            ->where('sale_date', '<=', $to)
            ->with(['farmer:id,farmer_no,full_name'])
            ->get();

        // Company purchases outstanding
        $companyPurchases = EspCompanyPurchase::where('esp_id', $espId)
            ->whereIn('status', ['pending', 'partial'])
            ->where('purchase_date', '<=', $to)
            ->get();

        $totalFarmerBalance   = $farmerSales->sum('balance');
        $totalCompanyBalance  = $companyPurchases->sum('balance');
        $companyDeduction     = min($totalFarmerBalance, $totalCompanyBalance);
        $netPayable           = $totalFarmerBalance - $companyDeduction;

        return ApiResponse::success([
            'esp_id'                  => $espId,
            'period_from'             => $from,
            'period_to'               => $to,
            'farmer_collections'      => round($totalFarmerBalance, 2),
            'company_purchases_balance'=> round($totalCompanyBalance, 2),
            'company_purchases_deducted'=> round($companyDeduction, 2),
            'net_payable'             => round($netPayable, 2),
            'farmer_sales'            => $farmerSales,
            'company_purchases'       => $companyPurchases,
        ]);
    }

    /** Post a settlement — applies deductions to farmer sales and company purchases */
    public function postSettlement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esp_id'      => 'required|exists:esp_providers,id',
            'period_from' => 'required|date',
            'period_to'   => 'required|date|after_or_equal:period_from',
            'actual_paid' => 'required|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $espId = $data['esp_id'];
            $to    = $data['period_to'];

            // Collect outstanding farmer sales (settle oldest first)
            $farmerSales = EspSale::where('esp_id', $espId)
                ->whereIn('status', ['pending', 'partial'])
                ->where('sale_date', '<=', $to)
                ->orderBy('id')
                ->get();

            $totalFarmerCollected = $farmerSales->sum('balance');

            foreach ($farmerSales as $sale) {
                $sale->deducted_amount = $sale->total_amount;
                $sale->balance         = 0;
                $sale->status          = 'settled';
                $sale->save();
            }

            // Collect outstanding company purchases
            $companyPurchases = EspCompanyPurchase::where('esp_id', $espId)
                ->whereIn('status', ['pending', 'partial'])
                ->where('purchase_date', '<=', $to)
                ->orderBy('id')
                ->get();

            $totalCompanyBalance = $companyPurchases->sum('balance');
            $companyDeduction    = min($totalFarmerCollected, $totalCompanyBalance);

            // Apply deduction to company purchases (oldest first)
            $remaining = $companyDeduction;
            foreach ($companyPurchases as $purchase) {
                if ($remaining <= 0) break;
                $apply = min($remaining, (float) $purchase->balance);
                $purchase->deducted_amount += $apply;
                $purchase->balance          -= $apply;
                $purchase->status           = $purchase->balance <= 0 ? 'settled' : 'partial';
                $purchase->save();
                $remaining -= $apply;
            }

            $netPayable = $totalFarmerCollected - $companyDeduction;

            $settlement = EspSettlement::create([
                'settlement_no'              => $this->nextRef('ESPSET', 'esp_settlements', 'settlement_no'),
                'esp_id'                     => $espId,
                'settlement_date'            => now()->toDateString(),
                'period_from'                => $data['period_from'],
                'period_to'                  => $data['period_to'],
                'farmer_collections'         => $totalFarmerCollected,
                'company_purchases_deducted' => $companyDeduction,
                'net_payable'                => $netPayable,
                'actual_paid'                => $data['actual_paid'],
                'status'                     => 'posted',
                'notes'                      => $data['notes'] ?? null,
                'created_by'                 => auth()->id(),
            ]);

            return ApiResponse::created($settlement->load('esp'), 'Settlement posted');
        });
    }

    /** Dashboard summary per ESP */
    public function dashboard(): JsonResponse
    {
        $providers = EspProvider::where('status', 'active')->get();

        $summary = $providers->map(function ($esp) {
            $farmerOutstanding  = EspSale::where('esp_id', $esp->id)->whereIn('status', ['pending', 'partial'])->sum('balance');
            $companyOutstanding = EspCompanyPurchase::where('esp_id', $esp->id)->whereIn('status', ['pending', 'partial'])->sum('balance');
            return [
                'id'                  => $esp->id,
                'esp_code'            => $esp->esp_code,
                'name'                => $esp->name,
                'farmer_outstanding'  => round((float) $farmerOutstanding, 2),
                'company_outstanding' => round((float) $companyOutstanding, 2),
                'net_payable'         => round((float) $farmerOutstanding - (float) $companyOutstanding, 2),
            ];
        });

        return ApiResponse::success($summary);
    }
}
