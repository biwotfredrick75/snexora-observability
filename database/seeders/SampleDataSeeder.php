<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds demo/sample data for testing.
 * Triggered by SEED_SAMPLE_DATA=true env var in start.sh.
 *
 * Seeding order respects FK dependencies:
 *   item_categories → items
 *   farmers (depend on milk_routes, farmer_banks from FreshInstallSeeder)
 *   suppliers
 *   customers → customer_branches (FK: customer_branches.debtor_no → customers)
 *   milk_prices
 *   milk_purchase_items (depend on farmers + milk_routes + shifts)
 *
 * Only seeds empty tables — does not truncate tables that have FK children
 * (SQL Server blocks TRUNCATE on referenced tables).
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Phase 1: Item master data ─────────────────────────────────────────
        $this->seedIfEmpty('item_categories', ItemCategorySeeder::class,  'item categories');
        $this->seedIfEmpty('items',           ItemSeeder::class,           'items');

        // ── Phase 2: Farmer master data ───────────────────────────────────────
        $this->seedIfEmpty('farmers',         FarmerSeeder::class,         'farmers (20 000)');

        // ── Phase 3: Supplier & customer master data ──────────────────────────
        $this->seedIfEmpty('suppliers',       SupplierSeeder::class,       'suppliers (30 000)');
        $this->seedIfEmpty('customers',       CustomerSeeder::class,       'customers');

        // CustomerBranchSeeder depends on customers existing
        if (DB::table('customers')->count() > 0) {
            $this->seedIfEmpty('customer_branches', CustomerBranchSeeder::class, 'customer branches');
        }

        // ── Phase 4: Milk pricing ─────────────────────────────────────────────
        $this->seedIfEmpty('milk_prices',     MilkPriceSeeder::class,      'milk prices');

        // ── Phase 5: Milk collections (require farmers + routes + shifts) ─────
        if (DB::table('farmers')->count() > 0 && DB::table('milk_routes')->count() > 0) {
            $this->seedIfEmpty('milk_purchase_items', MilkCollectionSeeder::class, 'milk collections');
        }
    }

    private function seedIfEmpty(string $table, string $seederClass, string $label): void
    {
        $count = DB::table($table)->count();

        if ($count > 0) {
            $this->command->info("  [skip] {$label} — {$count} rows already present");
            return;
        }

        $this->command->info("  [seed] {$label}…");
        $this->call($seederClass);
    }
}
