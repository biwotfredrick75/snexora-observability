<?php

namespace App\Jobs;

use App\Events\DashboardEvent;
use App\Models\MilkPurchase;
use App\Services\Farmers\MilkPurchaseApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BulkApproveMilkPurchasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes max
    public int $tries   = 1;    // no auto-retry — partial approvals are fine

    public function __construct(
        public readonly array  $ids,
        public readonly string $approvedBy,
    ) {}

    public function handle(MilkPurchaseApprovalService $approvalService): void
    {
        $approved = 0;
        $errors   = [];

        $purchases = MilkPurchase::whereIn('id', $this->ids)
            ->where('status', 'submitted')
            ->get();

        foreach ($purchases as $purchase) {
            DB::beginTransaction();
            try {
                $approvalService->postApproval($purchase, $this->approvedBy);
                DB::commit();
                $approved++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = "#{$purchase->id}: " . $e->getMessage();
                Log::error("BulkApprove failed for purchase #{$purchase->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        broadcast(new DashboardEvent('milk_purchase', 'bulk_approved', [
            'approved' => $approved,
            'failed'   => count($errors),
            'errors'   => $errors,
            'message'  => "{$approved} approved" . (count($errors) ? ', ' . count($errors) . ' failed' : ''),
        ]));
    }
}
