<?php

namespace App\Http\Controllers\Etims;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EtimsController extends Controller
{
    private string $gatewayUrl;
    private string $apiKey;
    private string $tin;
    private string $bhfId;

    public function __construct()
    {
        $prefs = \DB::table('company_preferences')->first();

        $this->gatewayUrl = rtrim($prefs?->etims_gateway_url ?: config('etims.gateway_url', 'http://localhost:8080'), '/');
        $this->apiKey     = $prefs?->etims_api_key    ?: config('etims.api_key', 'your-secret-key-here');
        $this->tin        = $prefs?->kra_pin           ?: config('etims.tin', '');
        $this->bhfId      = $prefs?->etims_bhf_id      ?: config('etims.bhf_id', '00');
    }

    // ── Items ─────────────────────────────────────────────────────────────────

    public function items(Request $request): JsonResponse
    {
        $q = $request->input('q', '');

        $query = DB::table('items as i')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->select([
                'i.stock_id', 'i.description', 'i.inactive',
                'i.etims_item_type', 'i.etims_taxation_type', 'i.etims_item_code',
                'i.etims_stock_class', 'i.etims_packaging_unit', 'i.etims_quantity_unit',
                'i.etims_item_name', 'i.etims_stock_category', 'i.etims_origin_nation',
                'c.description as category',
            ])
            ->when($q, fn($qb) => $qb->where(function ($qb2) use ($q) {
                $qb2->where('i.stock_id', 'like', "%{$q}%")
                    ->orWhere('i.description', 'like', "%{$q}%")
                    ->orWhere('i.etims_item_code', 'like', "%{$q}%");
            }))
            ->orderBy('i.stock_id');

        return ApiResponse::paginated($query->paginate((int) $request->input('per_page', 20)));
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $item = Item::where('stock_id', $id)->firstOrFail();

        $validated = $request->validate([
            'etims_item_type'      => 'nullable|string|max:5',
            'etims_taxation_type'  => 'nullable|string|max:5',
            'etims_item_code'      => 'nullable|string|max:50',
            'etims_stock_class'    => 'nullable|string|max:10',
            'etims_packaging_unit' => 'nullable|string|max:10',
            'etims_quantity_unit'  => 'nullable|string|max:10',
            'etims_item_name'      => 'nullable|string|max:200',
            'etims_stock_category' => 'nullable|string|max:5',
            'etims_origin_nation'  => 'nullable|string|max:5',
        ]);

        DB::table('items')->where('stock_id', $id)->update(array_filter($validated, fn($v) => $v !== null));

        return ApiResponse::updated($item->fresh(), 'eTIMS classification updated');
    }

    public function registerItem(string $id): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $item = DB::table('items')->where('stock_id', $id)->first();
        if (!$item) return ApiResponse::notFound('Item not found');

        $payload = [
            'tin'        => $this->tin,
            'bhfId'      => $this->bhfId,
            'itemCd'     => $item->etims_item_code ?: $item->stock_id,
            'itemClsCd'  => $item->etims_stock_class ?: '99990000',
            'itemTyCd'   => $item->etims_item_type ?: '2',
            'itemNm'     => $item->etims_item_name ?: $item->description,
            'itemStdNm'  => $item->description,
            'orgnNatCd'  => $item->etims_origin_nation ?: 'KE',
            'pkgUnitCd'  => $item->etims_packaging_unit ?: 'NO',
            'qtyUnitCd'  => $item->etims_quantity_unit ?: 'NO',
            'taxTyCd'    => $item->etims_taxation_type ?: 'B',
            'btchNo'     => null,
            'bcd'        => $item->bar_code ?: null,
            'splyAmt'    => 0,
            'regrNm'     => 'System',
            'regrId'     => 'system',
            'modrNm'     => 'System',
            'modrId'     => 'system',
        ];

        $result = $this->gateway('items/saveItems', $payload);

        if (($result['resultCd'] ?? '999') === '000') {
            DB::table('items')->where('stock_id', $id)->update([
                'etims_item_code' => $item->etims_item_code ?: $item->stock_id,
            ]);
        }

        return ApiResponse::success($result, $result['resultMsg'] ?? 'Done');
    }

    public function registerAllItems(): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $items = DB::table('items')
            ->whereNull('etims_item_code')
            ->where('inactive', false)
            ->get(['stock_id', 'description', 'etims_item_type', 'etims_taxation_type',
                   'etims_stock_class', 'etims_packaging_unit', 'etims_quantity_unit',
                   'etims_item_name', 'etims_origin_nation', 'bar_code']);

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($items as $item) {
            $payload = [
                'tin'        => $this->tin,
                'bhfId'      => $this->bhfId,
                'itemCd'     => $item->stock_id,
                'itemClsCd'  => $item->etims_stock_class ?: '99990000',
                'itemTyCd'   => $item->etims_item_type ?: '2',
                'itemNm'     => $item->etims_item_name ?: $item->description,
                'itemStdNm'  => $item->description,
                'orgnNatCd'  => $item->etims_origin_nation ?: 'KE',
                'pkgUnitCd'  => $item->etims_packaging_unit ?: 'NO',
                'qtyUnitCd'  => $item->etims_quantity_unit ?: 'NO',
                'taxTyCd'    => $item->etims_taxation_type ?: 'B',
                'bcd'        => $item->bar_code ?: null,
                'splyAmt'    => 0,
                'regrNm'     => 'System',
                'regrId'     => 'system',
                'modrNm'     => 'System',
                'modrId'     => 'system',
            ];

            $result = $this->gateway('items/saveItems', $payload);
            if (($result['resultCd'] ?? '999') === '000') {
                DB::table('items')->where('stock_id', $item->stock_id)->update([
                    'etims_item_code' => $item->stock_id,
                ]);
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['stock_id' => $item->stock_id, 'msg' => $result['resultMsg'] ?? '?'];
            }
        }

        return ApiResponse::success($results, "Processed {$items->count()} items");
    }

    public function kraItems(): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $result = $this->gateway('items/selectItems', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);

        return ApiResponse::success($result);
    }

    // ── Purchases (GRNs) ──────────────────────────────────────────────────────

    public function purchases(Request $request): JsonResponse
    {
        $q = $request->input('q', '');

        $query = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->where('po.type', 'grn')
            ->select([
                'po.id', 'po.po_no', 'po.status', 'po.created_at',
                'po.supplier_reference', 'po.amount_total',
                'po.etims_status', 'po.etims_invc_no', 'po.etims_rpt_no',
                'po.etims_stamped_at', 'po.etims_error',
                's.supplierId as supplier_id', 's.supplierName as supplier_name',
                's.gstNumber as supplier_tin',
            ])
            ->when($q, fn($qb) => $qb->where(function ($qb2) use ($q) {
                $qb2->where('po.po_no', 'like', "%{$q}%")
                    ->orWhere('s.supp_name', 'like', "%{$q}%")
                    ->orWhere('po.supplier_reference', 'like', "%{$q}%");
            }))
            ->orderByDesc('po.created_at');

        return ApiResponse::paginated($query->paginate((int) $request->input('per_page', 20)));
    }

    public function registerPurchase(int $id): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $po = PurchaseOrder::with(['items', 'supplier'])->findOrFail($id);

        if ($po->type !== 'grn') {
            return ApiResponse::error('Only GRN purchase orders can be registered with KRA', 422);
        }
        if (in_array($po->etims_status ?? '', ['signed', 'stamped'])) {
            return ApiResponse::error('Already registered with KRA', 422);
        }

        [$itemList, $taxBreakdown] = $this->buildPurchaseItemsAndTax($po->items);

        $pchsDt = $po->created_at instanceof \Carbon\Carbon
            ? $po->created_at->format('Ymd')
            : substr($po->created_at ?? now()->toDateString(), 0, 10);

        $payload = array_merge([
            'tin'         => $this->tin,
            'bhfId'       => $this->bhfId,
            'invcNo'      => $po->id,
            'orgInvcNo'   => $po->id,
            'spplrTin'    => $po->supplier?->gstNumber ?: null,
            'spplrNm'     => $po->supplier?->supplierName ?: null,
            'spplrInvcNo' => null,
            'regTyCd'     => 'M',
            'pchsTyCd'    => 'N',
            'rcptTyCd'    => 'P',
            'pmtTyCd'     => '01',
            'pchsSttsCd'  => '02',
            'cfmDt'       => now()->format('YmdHis'),
            'pchsDt'      => $pchsDt,
            'wrhsDt'      => null,
            'cnclReqDt'   => null,
            'cnclDt'      => null,
            'rfdDt'       => null,
            'totItemCnt'  => count($itemList),
        ], $taxBreakdown, [
            'totAmt'   => round((float) ($po->amount_total ?? 0), 2),
            'regrNm'   => $po->raised_by ?? 'System',
            'regrId'   => $po->raised_by ?? 'system',
            'modrNm'   => $po->raised_by ?? 'System',
            'modrId'   => $po->raised_by ?? 'system',
            'itemList' => $itemList,
        ]);

        $result   = $this->gateway('trnsPurchase/savePurchases', $payload);
        $resultCd = $result['resultCd'] ?? '999';
        $data     = $result['data'] ?? null;
        $success  = $resultCd === '000';

        DB::table('purchase_orders')->where('id', $id)->update([
            'etims_status'     => $success ? 'stamped' : 'failed',
            'etims_invc_no'    => $data['invcNo']  ?? null,
            'etims_rpt_no'     => $data['rptNo']   ?? null,
            'etims_stamped_at' => $success ? now() : null,
            'etims_error'      => !$success ? ($result['resultMsg'] ?? null) : null,
        ]);

        return ApiResponse::success($result, $result['resultMsg'] ?? 'Done');
    }

    public function kraPurchases(): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $result = $this->gateway('trnsPurchase/selectTrnsPurchaseSales', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);

        return ApiResponse::success($result);
    }

    // ── Sales Invoices ────────────────────────────────────────────────────────

    public function invoices(Request $request): JsonResponse
    {
        $q      = $request->input('q', '');
        $status = $request->input('status', '');

        $query = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->select([
                'si.id', 'si.inv_no', 'si.invoice_date', 'si.status',
                'si.amount_total', 'si.debtor_no',
                'si.etims_status', 'si.etims_invc_no', 'si.etims_rpt_no',
                'si.etims_mrc_no', 'si.etims_stamped_at', 'si.etims_error',
                'si.etims_qr_path',
                'c.name as customer_name',
            ])
            ->when($q, fn($qb) => $qb->where(function ($qb2) use ($q) {
                $qb2->where('si.inv_no', 'like', "%{$q}%")
                    ->orWhere('c.name', 'like', "%{$q}%");
            }))
            ->when($status, fn($qb) => $qb->where('si.etims_status', $status))
            ->orderByDesc('si.invoice_date');

        return ApiResponse::paginated($query->paginate((int) $request->input('per_page', 20)));
    }

    // ── Stock Movements ───────────────────────────────────────────────────────

    public function kraStock(): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $result = $this->gateway('stock/selectStockItems', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);

        return ApiResponse::success($result);
    }

    public function saveStockMovement(Request $request): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $validated = $request->validate([
            'sarNo'      => 'required|string',
            'ocrnDt'     => 'required|string',
            'totItemCnt' => 'required|integer',
            'totTaxblAmt'=> 'required|numeric',
            'totTaxAmt'  => 'required|numeric',
            'totAmt'     => 'required|numeric',
            'regrNm'     => 'nullable|string',
            'regrId'     => 'nullable|string',
            'itemList'   => 'required|array|min:1',
        ]);

        $payload = array_merge([
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ], $validated, [
            'regrNm' => $validated['regrNm'] ?? 'System',
            'regrId' => $validated['regrId'] ?? 'system',
            'modrNm' => $validated['regrNm'] ?? 'System',
            'modrId' => $validated['regrId'] ?? 'system',
        ]);

        $result = $this->gateway('stock/saveStockItems', $payload);

        return ApiResponse::success($result, $result['resultMsg'] ?? 'Done');
    }

    // ── Customer Lookup ───────────────────────────────────────────────────────

    public function lookupCustomer(Request $request): JsonResponse
    {
        if ($err = $this->requiresConfig()) return ApiResponse::error($err['error'], 422);

        $tin = $request->input('tin');
        if (!$tin) return ApiResponse::error('TIN is required', 422);

        $result = $this->gateway('customers/selectCustomer', [
            'tin'    => $this->tin,
            'bhfId'  => $this->bhfId,
            'custTin'=> $tin,
        ]);

        return ApiResponse::success($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPurchaseItemsAndTax($items): array
    {
        $taxGroups = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0, 'E' => 0.0];
        $itemList  = [];

        foreach ($items as $seq => $line) {
            $itm = DB::table('items')->where('stock_id', $line->stock_id)
                ->select('etims_taxation_type', 'etims_item_code', 'etims_stock_class',
                         'etims_packaging_unit', 'etims_quantity_unit')
                ->first();

            $taxTyCd  = $itm?->etims_taxation_type ?: 'B';
            $taxRate  = match ($taxTyCd) { 'B' => 16.0, 'E' => 8.0, default => 0.0 };
            $total    = (float) ($line->line_total ?? 0);
            $qty      = (float) ($line->qty ?? 1);
            $prc      = (float) ($line->price_before_tax ?? 0);

            $taxblAmt = $taxRate > 0 ? round($total / (1 + $taxRate / 100), 2) : $total;
            $taxAmt   = round($total - $taxblAmt, 2);

            $taxGroups[$taxTyCd] += $total;

            $itemList[] = [
                'itemSeq'   => $seq + 1,
                'itemCd'    => $itm?->etims_item_code ?: $line->stock_id,
                'itemClsCd' => $itm?->etims_stock_class ?: '99990000',
                'itemNm'    => $line->description,
                'bcd'       => null,
                'pkgUnitCd' => $itm?->etims_packaging_unit ?: 'NO',
                'pkg'       => $qty,
                'qtyUnitCd' => $itm?->etims_quantity_unit ?: 'NO',
                'qty'       => $qty,
                'prc'       => $prc,
                'splyAmt'   => $taxblAmt,
                'dcRt'      => 0,
                'dcAmt'     => 0,
                'taxTyCd'   => $taxTyCd,
                'taxblAmt'  => $taxblAmt,
                'taxAmt'    => $taxAmt,
                'totAmt'    => $total,
            ];
        }

        $breakdown = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $t) {
            $total    = $taxGroups[$t];
            $rate     = match ($t) { 'B' => 16.0, 'E' => 8.0, default => 0.0 };
            $taxbl    = $rate > 0 ? round($total / (1 + $rate / 100), 2) : $total;
            $taxAmt   = round($total - $taxbl, 2);
            $breakdown["taxblAmt{$t}"] = $taxbl;
            $breakdown["taxRt{$t}"]    = $rate;
            $breakdown["taxAmt{$t}"]   = $taxAmt;
        }

        $breakdown['totTaxblAmt'] = round(array_sum([$breakdown['taxblAmtA'], $breakdown['taxblAmtB'], $breakdown['taxblAmtC'], $breakdown['taxblAmtD'], $breakdown['taxblAmtE']]), 2);
        $breakdown['totTaxAmt']   = round(array_sum([$breakdown['taxAmtA'], $breakdown['taxAmtB'], $breakdown['taxAmtC'], $breakdown['taxAmtD'], $breakdown['taxAmtE']]), 2);

        return [$itemList, $breakdown];
    }

    private function requiresConfig(): ?array
    {
        if (empty($this->tin)) {
            return ['error' => 'KRA PIN not configured. Go to KRA eTIMS → Settings to set it up.'];
        }
        if (empty($this->apiKey)) {
            return ['error' => 'Gateway API key not configured. Go to KRA eTIMS → Settings to set it up.'];
        }
        return null;
    }

    private function gateway(string $endpoint, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->gatewayUrl}/api/v1/{$endpoint}", $payload);

            if (!$response->successful()) {
                $body = $response->json() ?? [];
                $raw  = $body['error'] ?? $body['message'] ?? $body['resultMsg'] ?? "Gateway error {$response->status()}";
                // Strip HTML / oversized responses (KRA sometimes returns HTML error pages)
                $isHtml = str_contains($raw, '<html') || str_contains($raw, '<!DOCTYPE') || strlen($raw) > 300;
                $msg    = $isHtml ? "KRA server error (HTTP {$response->status()}) — check gateway logs" : $raw;
                Log::error("eTIMS gateway [{$endpoint}] HTTP {$response->status()}: {$raw}");
                return ['resultCd' => '999', 'resultMsg' => $msg];
            }

            $body = $response->json() ?? [];
            // Gateway itself may return {"error": "..."} without a resultCd
            if (!isset($body['resultCd']) && isset($body['error'])) {
                return ['resultCd' => '999', 'resultMsg' => $body['error']];
            }
            return $body ?: ['resultCd' => '999', 'resultMsg' => 'Empty response'];
        } catch (\Throwable $e) {
            Log::error("eTIMS gateway [{$endpoint}]: " . $e->getMessage());
            return ['resultCd' => '999', 'resultMsg' => $e->getMessage()];
        }
    }
}
