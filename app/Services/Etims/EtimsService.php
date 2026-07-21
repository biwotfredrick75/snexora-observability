<?php

namespace App\Services\Etims;

use App\Models\CreditNote;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EtimsService
{
    private string $gatewayUrl;
    private string $apiKey;

    public function __construct()
    {
        // Company Preferences (set via Setup > eTIMS) takes priority over the
        // static .env/config default — same resolution order as
        // EtimsController/EtimsSetupController, which this class must match.
        // Without this, stamping silently bypassed the etims-gateway service
        // entirely and hit whatever else happened to be on the config default
        // port instead.
        $prefs = DB::table('company_preferences')->first();

        $this->gatewayUrl = rtrim($prefs?->etims_gateway_url ?: config('etims.gateway_url', 'http://localhost:8080'), '/');
        $this->apiKey     = $prefs?->etims_api_key ?: config('etims.api_key', 'your-secret-key-here');
    }

    // ── Public entry points ───────────────────────────────────────────────────

    public function stampInvoice(SalesInvoice $invoice): array
    {
        $invoice->loadMissing('items');
        [$itemList, $taxBreakdown] = $this->buildItemsAndTax($invoice->items, false);

        $custTin = null;
        $custNm  = null;
        if ($invoice->debtor_no) {
            $cust = DB::table('customers')->where('debtor_no', $invoice->debtor_no)
                ->select('kra_pin', 'name')->first();
            $custTin = $cust?->kra_pin ?: null;
            $custNm  = $cust?->name    ?: null;
        }

        $salesDt = $invoice->invoice_date instanceof \Carbon\Carbon
            ? $invoice->invoice_date->format('Ymd')
            : substr($invoice->invoice_date ?? now()->toDateString(), 0, 10);

        $payload = array_merge([
            'trdInvcNo'   => $invoice->inv_no,
            'invcNo'      => 0,
            'orgInvcNo'   => 0,
            'custTin'     => $custTin,
            'custNm'      => $custNm,
            'salesTyCd'   => 'N',
            'rcptTyCd'    => 'S',
            'pmtTyCd'     => '01',
            'salesSttsCd' => '02',
            'cfmDt'       => now()->format('YmdHis'),
            'salesDt'     => $salesDt,
            'totItemCnt'  => count($itemList),
        ], $taxBreakdown, [
            'totAmt'      => round((float) $invoice->amount_total, 2),
            'prchrAcptcYn'=> 'N',
            'regrNm'      => $invoice->created_by ?? 'System',
            'regrId'      => $invoice->created_by ?? 'system',
            'modrNm'      => $invoice->created_by ?? 'System',
            'modrId'      => $invoice->created_by ?? 'system',
            'itemList'    => $itemList,
        ]);

        $response = $this->callGateway('trnsSales/saveSales', $payload);
        $this->persistResult($invoice, $response, 'invoice');

        return $response;
    }

    public function stampCreditNote(CreditNote $cn): array
    {
        $cn->loadMissing('items');
        [$itemList, $taxBreakdown] = $this->buildItemsAndTax($cn->items, true);

        $custTin = null;
        if ($cn->debtor_no) {
            $custTin = DB::table('customers')->where('debtor_no', $cn->debtor_no)->value('kra_pin') ?: null;
        }

        // Link to original invoice's KRA invoice number if available
        $orgInvcNo = 0;
        if ($cn->inv_id) {
            $orgInvcNo = (int) DB::table('sales_invoices')
                ->where('id', $cn->inv_id)->value('etims_invc_no') ?: 0;
        }

        $cnDate = $cn->cn_date instanceof \Carbon\Carbon
            ? $cn->cn_date->format('Ymd')
            : substr($cn->cn_date ?? now()->toDateString(), 0, 10);

        $payload = array_merge([
            'trdInvcNo'   => $cn->cn_no,
            'invcNo'      => 0,
            'orgInvcNo'   => $orgInvcNo,
            'custTin'     => $custTin,
            'salesTyCd'   => 'N',
            'rcptTyCd'    => 'R',
            'pmtTyCd'     => '01',
            'salesSttsCd' => '02',
            'cfmDt'       => now()->format('YmdHis'),
            'salesDt'     => $cnDate,
            'totItemCnt'  => count($itemList),
        ], $taxBreakdown, [
            'totAmt'      => round((float) $cn->amount_total, 2),
            'prchrAcptcYn'=> 'N',
            'regrNm'      => $cn->created_by ?? 'System',
            'regrId'      => $cn->created_by ?? 'system',
            'modrNm'      => $cn->created_by ?? 'System',
            'modrId'      => $cn->created_by ?? 'system',
            'itemList'    => $itemList,
        ]);

        $response = $this->callGateway('trnsSales/saveSales', $payload);
        $this->persistResult($cn, $response, 'credit_note');

        return $response;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function buildItemsAndTax($items, bool $negate): array
    {
        $taxGroups = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0, 'E' => 0.0];
        $itemList  = [];

        foreach ($items as $seq => $lineItem) {
            $itm = DB::table('items')->where('stock_id', $lineItem->stock_id)
                ->select('etims_taxation_type', 'etims_item_code', 'etims_stock_class',
                         'etims_packaging_unit', 'etims_quantity_unit', 'etims_item_type')
                ->first();

            $taxTyCd  = $itm?->etims_taxation_type ?: 'B';
            $taxRate  = $this->taxRate($taxTyCd);
            $lineTotal = (float) $lineItem->line_total;
            $qty      = (float) $lineItem->qty;
            $prc      = (float) $lineItem->price;
            $dcRt     = (float) ($lineItem->discount_pct ?? 0);

            // KRA expects tax-exclusive supply amount
            $taxblAmt = $taxRate > 0 ? round($lineTotal / (1 + $taxRate / 100), 2) : $lineTotal;
            $taxAmt   = round($lineTotal - $taxblAmt, 2);
            $dcAmt    = round($lineTotal * $dcRt / 100, 2);

            $taxGroups[$taxTyCd] += $lineTotal;

            $itemList[] = [
                'itemSeq'   => $seq + 1,
                'itemCd'    => $itm?->etims_item_code ?: $lineItem->stock_id,
                'itemClsCd' => $itm?->etims_stock_class ?: null,
                'itemNm'    => $lineItem->description,
                'pkgUnitCd' => $itm?->etims_packaging_unit ?: 'PC',
                'pkg'       => $qty,
                'qtyUnitCd' => $itm?->etims_quantity_unit ?: 'U',
                'qty'       => $qty,
                'prc'       => $prc,
                'splyAmt'   => $taxblAmt,
                'dcRt'      => $dcRt,
                'dcAmt'     => $dcAmt,
                'taxTyCd'   => $taxTyCd,
                'taxblAmt'  => $taxblAmt,
                'taxAmt'    => $taxAmt,
                'totAmt'    => $lineTotal,
            ];
        }

        $taxBreakdown = $this->buildTaxBreakdown($taxGroups);

        return [$itemList, $taxBreakdown];
    }

    private function taxRate(string $taxTyCd): float
    {
        return match ($taxTyCd) {
            'B' => 16.0,
            'E' => 8.0,
            default => 0.0, // A=Exempt, C=0%, D=Non-VAT
        };
    }

    private function buildTaxBreakdown(array $taxGroups): array
    {
        $breakdown = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $t) {
            $total     = $taxGroups[$t] ?? 0.0;
            $taxRate   = $this->taxRate($t);
            $taxblAmt  = $taxRate > 0 ? round($total / (1 + $taxRate / 100), 2) : $total;
            $taxAmt    = round($total - $taxblAmt, 2);

            $breakdown["taxblAmt{$t}"] = $taxblAmt;
            $breakdown["taxRt{$t}"]    = $taxRate;
            $breakdown["taxAmt{$t}"]   = $taxAmt;
        }

        $totTaxblAmt = array_sum(array_filter([
            $breakdown['taxblAmtA'], $breakdown['taxblAmtB'],
            $breakdown['taxblAmtC'], $breakdown['taxblAmtD'], $breakdown['taxblAmtE'],
        ]));
        $totTaxAmt = array_sum(array_filter([
            $breakdown['taxAmtA'], $breakdown['taxAmtB'],
            $breakdown['taxAmtC'], $breakdown['taxAmtD'], $breakdown['taxAmtE'],
        ]));

        $breakdown['totTaxblAmt'] = round($totTaxblAmt, 2);
        $breakdown['totTaxAmt']   = round($totTaxAmt, 2);

        return $breakdown;
    }

    private function callGateway(string $endpoint, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->gatewayUrl}/api/v1/{$endpoint}", $payload);

            return $response->json() ?? ['resultCd' => '999', 'resultMsg' => 'Empty response'];
        } catch (\Throwable $e) {
            Log::error("eTIMS gateway error [{$endpoint}]: " . $e->getMessage());
            return ['resultCd' => '999', 'resultMsg' => $e->getMessage()];
        }
    }

    private function persistResult(object $model, array $response, string $type): void
    {
        $resultCd = $response['resultCd'] ?? '999';
        $data     = $response['data'] ?? null;

        $isSuccess = in_array($resultCd, ['000']);
        $isOffline = $isSuccess && empty($data['intrIData'] ?? '');

        $status = match (true) {
            !$isSuccess => 'failed',
            $isOffline  => 'signed',   // VSCU signed locally, not yet transmitted to KRA
            default     => 'stamped',  // Fully stamped and confirmed by KRA
        };

        $update = [
            'etims_status'     => $status,
            'etims_mrc_no'     => $data['mrcNo']             ?? null,
            'etims_rpt_no'     => $data['rptNo']             ?? null,
            'etims_intr_data'  => $data['intrIData']         ?? null,
            'etims_rct_sign'   => $data['rctSign']           ?? null,
            'etims_rcpt_date'  => $data['VSCURcptPbctDate']  ?? null,
            'etims_stamped_at' => $isSuccess ? now() : null,
            'etims_error'      => !$isSuccess ? ($response['resultMsg'] ?? null) : null,
            // Cleared by default — only set below when a real QR is (re)generated.
            // A re-stamp attempt that no longer qualifies (or never did) must not
            // leave a stale path from a prior attempt pointing at nothing.
            'etims_qr_path'    => null,
        ];

        // A "receipt" QR code is only meaningful once KRA has actually returned
        // signed data (intrIData) — that's the cryptographic token the code
        // must carry. An invoice that's merely "signed" locally by the VSCU
        // but not yet transmitted has no real signature to encode, so no QR
        // is generated for it (a placeholder/debug string here would look
        // scannable but resolve to nothing on KRA's verification page).
        if ($isSuccess && !empty($data['intrIData'])) {
            $update['etims_qr_path'] = $this->generateQr(
                $this->receiptVerifyUrl($data['intrIData']), $type, $model->id
            );
        }

        $model->update($update);
    }

    /**
     * KRA's standard eTIMS receipt-verification link — scanning it opens
     * KRA's own portal, which looks up and displays the invoice (buyer/seller
     * PINs, tax breakdown, items) against the signed `Data` token. The QR
     * itself only needs to carry this URL, not a copy of the invoice fields.
     */
    private function receiptVerifyUrl(string $intrIData): string
    {
        $host = config('etims.env') === 'prod'
            ? 'https://etims.kra.go.ke'
            : 'https://etims-sbx.kra.go.ke';

        return "{$host}/common/link/etims/receipt/indexEtimsReceiptData?Data=" . urlencode($intrIData);
    }

    private function generateQr(string $data, string $type, int $id): ?string
    {
        try {
            $dir      = "etims/qr/{$type}";
            $filename = "{$id}.svg";
            $path     = "{$dir}/{$filename}";
            $disk     = Storage::disk('public');

            $disk->makeDirectory($dir);

            $svg = QrCode::format('svg')->size(300)->margin(1)->errorCorrection('M')->generate($data);
            $disk->put($path, (string) $svg);

            return "etims/qr/{$type}/{$filename}";
        } catch (\Throwable $e) {
            Log::error("eTIMS QR generation failed: " . $e->getMessage());
            return null;
        }
    }
}
