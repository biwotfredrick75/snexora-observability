<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MilkCollectionSeeder extends Seeder
{
    // Fixed unit price (KES per litre) used across all seeded collections
    private const UNIT_PRICE = 45.0000;

    public function run(): void
    {
        $startDate = '2026-03-01';
        $endDate   = '2026-03-15';

        // ── Reference data ────────────────────────────────────────────────────
        $farmerIds = DB::table('farmers')
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();

        if (empty($farmerIds)) {
            $this->command->warn('No active farmers found — run FarmerSeeder first.');
            return;
        }

        $routeIds = DB::table('milk_routes')->pluck('id')->toArray();
        $shiftIds = DB::table('milk_collection_shifts')->where('active', true)->pluck('id')->toArray();
        $graderIds = DB::table('inventory_locations')
            ->whereIn('type', ['grader', 'vendor'])
            ->where('inactive', false)
            ->pluck('id')
            ->toArray();

        if (empty($routeIds))  { $this->command->warn('No milk routes — route_id will be null.'); }
        if (empty($shiftIds))  { $this->command->warn('No active shifts — shift_id will be null.'); }
        if (empty($graderIds)) { $this->command->warn('No grader locations — grader_id will be null.'); }

        // ── Build date range ──────────────────────────────────────────────────
        $dates = [];
        $cursor = new \DateTime($startDate);
        $end    = new \DateTime($endDate);
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        $totalFarmers = count($farmerIds);
        $totalDays    = count($dates);
        $grandTotal   = $totalFarmers * $totalDays;

        $this->command->info("Seeding milk collections: {$totalFarmers} farmers × {$totalDays} days = {$grandTotal} items…");
        $bar = $this->command->getOutput()->createProgressBar($grandTotal);
        $bar->start();

        $itemChunkSize = 500;   // rows per bulk insert into milk_purchase_items
        $refNo         = 1;     // incrementing reference counter

        foreach ($dates as $date) {
            $dateTime = $date . ' 06:00:00'; // morning collection time

            $routeId  = $routeIds  ? $routeIds[array_rand($routeIds)]   : null;
            $shiftId  = $shiftIds  ? $shiftIds[array_rand($shiftIds)]   : null;
            $graderId = $graderIds ? $graderIds[array_rand($graderIds)] : null;

            // ── Create one milk_purchases header per day ──────────────────────
            $totalQty = 0;
            $purchaseId = DB::table('milk_purchases')->insertGetId([
                'route_id'     => $routeId,
                'grader_id'    => $graderId,
                'shift_id'     => $shiftId,
                'pricing_type' => 'normal',
                'reference_no' => 'SEED-' . str_pad($refNo++, 6, '0', STR_PAD_LEFT),
                'invoice_date' => $date,
                'due_date'     => $date,
                'total_qty'    => 0,         // updated after items are inserted
                'total_amount' => 0,
                'status'       => 'posted',
                'created_by'   => 'seeder',
                'created_at'   => $dateTime,
                'updated_at'   => $dateTime,
            ]);

            // ── Insert items in chunks ────────────────────────────────────────
            $itemBatch  = [];
            $dayQty     = 0;
            $dayAmount  = 0.0;

            foreach ($farmerIds as $farmerId) {
                $qty        = mt_rand(5, 30) + (mt_rand(0, 9) / 10);  // 5.0 – 30.9 L
                $qty        = round($qty, 3);
                $totalPrice = round($qty * self::UNIT_PRICE, 4);
                $dayQty    += $qty;
                $dayAmount += $totalPrice;

                $itemBatch[] = [
                    'purchase_id' => $purchaseId,
                    'farmer_id'   => $farmerId,
                    'quantity'    => $qty,
                    'unit_price'  => self::UNIT_PRICE,
                    'total_price' => $totalPrice,
                    'unique_key'  => $purchaseId . '-' . $farmerId,
                    'created_at'  => $dateTime,
                    'updated_at'  => $dateTime,
                ];

                if (count($itemBatch) >= $itemChunkSize) {
                    DB::table('milk_purchase_items')->insert($itemBatch);
                    $bar->advance(count($itemBatch));
                    $itemBatch = [];
                }
            }

            if (!empty($itemBatch)) {
                DB::table('milk_purchase_items')->insert($itemBatch);
                $bar->advance(count($itemBatch));
                $itemBatch = [];
            }

            // ── Back-fill totals on the header ────────────────────────────────
            DB::table('milk_purchases')->where('id', $purchaseId)->update([
                'total_qty'    => round($dayQty, 3),
                'total_amount' => round($dayAmount, 2),
                'updated_at'   => $dateTime,
            ]);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("✅ Milk collections seeded: {$totalDays} batches, ~{$grandTotal} items.");
    }
}
