<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainAnchor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client that calls the Go blockchain micro-service.
 *
 * All methods are fire-and-forget from the caller's perspective:
 * if the blockchain service is unreachable, the payment still completes —
 * only an error is logged and the anchor record is marked 'failed'.
 */
class BlockchainService
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl        = rtrim(config('blockchain.url', 'http://localhost:9000'), '/');
        $this->apiKey         = config('blockchain.api_key', 'change-me-in-production');
        $this->timeoutSeconds = (int) config('blockchain.timeout_seconds', 10);
    }

    // ── Anchor helpers (one per contract type) ────────────────────────────────

    /**
     * Anchor a completed farmer payment batch (maps to final_payment contract type).
     */
    public function anchorFarmerPaymentBatch(
        int    $batchId,
        string $reference,
        string $period,        // e.g. "2026-03"
        float  $totalGross,
        float  $totalDeductions,
        float  $totalNet,
        int    $farmerCount,
        string $preparedBy
    ): ?BlockchainAnchor {
        $payload = [
            'payment_id'          => (string) $batchId,
            'trans_no'            => $reference,
            'farmer_id'           => 'BATCH',
            'period'              => $period,
            'total_litres'        => 0,
            'total_gross_value'   => $totalGross,
            'total_advances_paid' => 0,
            'total_loan_repayments' => 0,
            'total_deductions'    => $totalDeductions,
            'net_payable'         => $totalNet,
            'collection_refs'     => [],
            'advance_refs'        => [],
            'payment_method'      => 'batch',
            'account_ref'         => '',
            'status'              => 'transferred',
            'prepared_by'         => $preparedBy,
            'approved_by'         => '',
            'transferred_at'      => now()->toISOString(),
            '_meta'               => ['farmer_count' => $farmerCount],
        ];

        return $this->anchor('final_payment', $reference, $batchId, $payload);
    }

    /**
     * Anchor a posted customer payment (maps to invoice_settlement contract type).
     */
    public function anchorCustomerPayment(
        int    $paymentId,
        string $paymentNo,
        string $debtorNo,
        float  $amount,
        string $paymentDate,
        string $paymentType,
        string $createdBy
    ): ?BlockchainAnchor {
        $payload = [
            'invoice_id'        => (string) $paymentId,
            'trans_no'          => $paymentNo,
            'farmer_id'         => $debtorNo,
            'supplier_id'       => '',
            'items'             => [],
            'sub_total'         => $amount,
            'tax_amount'        => 0,
            'total_amount'      => $amount,
            'settlement_method' => $paymentType,
            'settlement_refs'   => [],
            'raised_by'         => $createdBy,
            'verified_by'       => $createdBy,
            'verified_at'       => $paymentDate,
            'on_chain_farmer_id'=> $debtorNo,
            'status'            => 'settled',
            'invoice_date'      => $paymentDate,
            'due_date'          => $paymentDate,
        ];

        return $this->anchor('invoice_settlement', $paymentNo, $paymentId, $payload);
    }

    /**
     * Anchor a posted payment voucher (supplier payment → advance_payment contract type).
     */
    public function anchorPaymentVoucher(
        int    $voucherId,
        string $pvnNo,
        int    $supplierId,
        float  $amount,
        string $datePaid,
        string $type,
        string $createdBy
    ): ?BlockchainAnchor {
        $payload = [
            'payment_id'        => (string) $voucherId,
            'trans_no'          => $pvnNo,
            'collection_trans_no' => '',
            'farmer_id'         => (string) $supplierId,
            'estimated_value'   => $amount,
            'advance_rate'      => 1.0,
            'gross_advance'     => $amount,
            'loan_deductions'   => 0,
            'other_deductions'  => 0,
            'net_advance'       => $amount,
            'payment_method'    => $type,
            'account_ref'       => '',
            'status'            => 'transferred',
            'issued_at'         => $datePaid,
            'issued_by'         => $createdBy,
        ];

        return $this->anchor('advance_payment', $pvnNo, $voucherId, $payload);
    }

    // ── Verification ──────────────────────────────────────────────────────────

    /**
     * Verify a doc_hash + tx_hash pair against what is on-chain.
     * Returns the raw array from the blockchain service, or null on failure.
     */
    public function verify(string $txHash, string $docHash): ?array
    {
        try {
            $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/api/v1/verify/{$txHash}", ['doc_hash' => $docHash]);

            if ($response->successful()) {
                return $response->json('data');
            }

            Log::warning('BlockchainService::verify failed', [
                'tx_hash'  => $txHash,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BlockchainService::verify exception', [
                'tx_hash' => $txHash,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Retrieve the stored anchor record for a document by trans_type + trans_no.
     */
    public function getAnchor(string $transType, string $transNo): ?BlockchainAnchor
    {
        return BlockchainAnchor::where('trans_type', $transType)
            ->where('trans_no', $transNo)
            ->latest()
            ->first();
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Post the payload to the blockchain service and persist the result.
     * Never throws — failures are captured as BlockchainAnchor with status='failed'.
     */
    private function anchor(
        string $transType,
        string $transNo,
        int    $transId,
        array  $payload
    ): ?BlockchainAnchor {
        // Create a pending record immediately so any failure is visible in the UI.
        $anchor = BlockchainAnchor::create([
            'trans_type' => $transType,
            'trans_no'   => $transNo,
            'trans_id'   => $transId,
            'doc_hash'   => '',      // filled in after the service responds
            'status'     => 'pending',
        ]);

        try {
            $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/api/v1/anchor", [
                    'trans_type' => $transType,
                    'trans_no'   => $transNo,
                    'trans_id'   => (string) $transId,
                    'payload'    => $payload,
                ]);

            if ($response->successful()) {
                $data = $response->json('data');
                $anchor->update([
                    'doc_hash'     => $data['doc_hash']     ?? '',
                    'tx_hash'      => $data['tx_hash']      ?? null,
                    'network'      => $data['network']      ?? null,
                    'status'       => $data['status']       ?? 'pending',
                    'explorer_url' => $data['explorer_url'] ?? null,
                    'anchored_at'  => $data['anchored_at']  ?? now(),
                ]);

                Log::info("BlockchainService: anchored {$transType} {$transNo}", [
                    'tx_hash'  => $data['tx_hash']  ?? null,
                    'doc_hash' => $data['doc_hash'] ?? null,
                ]);
            } else {
                $anchor->update([
                    'status'        => 'failed',
                    'error_message' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                ]);

                Log::error("BlockchainService: anchor failed for {$transType} {$transNo}", [
                    'http_status' => $response->status(),
                    'body'        => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            $anchor->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error("BlockchainService: exception anchoring {$transType} {$transNo}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $anchor->fresh();
    }
}
