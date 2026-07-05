<?php

namespace App\Http\Controllers\Esp;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EspProvider;
use App\Models\EspFarmerSale;
use App\Models\EspFarmerSaleItem;
use App\Models\EspCompanyPurchase;
use App\Models\EspCompanyPurchaseItem;
use App\Models\EspSettlement;
use App\Models\Farmer;
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
        $provider->load(['farmerSales', 'companyPurchases', 'settlements']);

        // Compute summary balances
        $provider->farmer_sales_outstanding  = $provider->farmerSales->where('status', '!=', 'settled')->sum('balance');
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
        $outstanding = EspFarmerSale::where('farmer_id', $farmerId)
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
        $q = EspFarmerSale::with(['esp:id,name,esp_code', 'farmer:id,farmer_no,full_name']);
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
            $sale = EspFarmerSale::create([
                'sale_no'         => $this->nextRef('ESPS', 'esp_farmer_sales', 'sale_no'),
                'esp_id'          => $data['esp_id'],
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
                EspFarmerSaleItem::create([
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

    public function showFarmerSale(EspFarmerSale $sale): JsonResponse
    {
        return ApiResponse::success($sale->load(['esp', 'farmer', 'items']));
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
        $farmerSales = EspFarmerSale::where('esp_id', $espId)
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
            $farmerSales = EspFarmerSale::where('esp_id', $espId)
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
            $farmerOutstanding  = EspFarmerSale::where('esp_id', $esp->id)->whereIn('status', ['pending', 'partial'])->sum('balance');
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
