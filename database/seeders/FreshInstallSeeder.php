<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Run this seeder on a fresh database to bootstrap everything needed
 * before any transaction data is entered.
 *
 * php8.3 artisan db:seed --class=FreshInstallSeeder
 */
class FreshInstallSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🚀 Nexora ERP — Fresh Installation Seed');
        $this->command->info('');

        // ── 1. Roles & Permissions ────────────────────────────────────────────
        $this->command->info('  [ 1/18] Seeding roles & permissions...');
        $this->call(RolePermissionSeeder::class);

        // ── 2. Admin user ─────────────────────────────────────────────────────
        $this->command->info('  [ 2/18] Creating admin user...');
        $this->seedAdminUser();

        // ── 3. Company preferences (singleton) ───────────────────────────────
        $this->command->info('  [ 3/18] Seeding company preferences...');
        $this->call(CompanyPreferencesSeeder::class);

        // ── 4. Fiscal years ───────────────────────────────────────────────────
        $this->command->info('  [ 4/18] Seeding fiscal years...');
        $this->call(FiscalYearSeeder::class);

        // ── 5. Transaction reference patterns ────────────────────────────────
        $this->command->info('  [ 5/18] Seeding transaction references...');
        $this->call(TransactionReferenceSeeder::class);

        // ── 6. GL setup (classes → groups → chart of accounts → settings) ────
        $this->command->info('  [ 6/18] Seeding GL account classes, groups & chart of accounts...');
        $this->call(GlAccountClassSeeder::class);
        $this->call(GlAccountGroupSeeder::class);
        $this->call(ChartOfAccountsSeeder::class);
        $this->call(GlSettingsSeeder::class);

        // ── 7. Tax types & groups ─────────────────────────────────────────────
        $this->command->info('  [ 7/18] Seeding tax types & groups...');
        $this->call(TaxTypeSeeder::class);

        // ── 8. Payment terms ──────────────────────────────────────────────────
        $this->command->info('  [ 8/18] Seeding payment terms...');
        $this->call(PaymentTermSeeder::class);

        // ── 9. Contact categories ─────────────────────────────────────────────
        $this->command->info('  [ 9/18] Seeding contact categories...');
        $this->call(ContactCategorySeeder::class);

        // ── 10. Shipping companies ────────────────────────────────────────────
        $this->command->info('  [10/18] Seeding shipping companies...');
        $this->call(ShippingCompanySeeder::class);

        // ── 11. Sales maintenance (types, areas, persons, groups, CN reasons, credit statuses) ──
        $this->command->info('  [11/18] Seeding sales maintenance data...');
        $this->call(SalesMaintenanceSeeder::class);

        // ── 12. Inventory locations ───────────────────────────────────────────
        $this->command->info('  [12/18] Seeding inventory locations...');
        $this->call(InventoryLocationSeeder::class);

        // ── 13. Farmer maintenance (payment terms, banks, checkoff) ───────────
        $this->command->info('  [13/18] Seeding farmer maintenance data...');
        $this->call(FarmerMaintenanceSeeder::class);
        $this->call(FarmerBankSeeder::class);
        $this->call(CheckoffServiceSeeder::class);

        // ── 14. Milk operations setup ─────────────────────────────────────────
        $this->command->info('  [14/18] Seeding milk operations setup...');
        $this->call(MilkRouteSeeder::class);
        $this->call(MilkStationSeeder::class);
        $this->call(MilkGunSeeder::class);
        $this->call(MilkCollectionSessionSeeder::class);
        $this->call(MilkCollectionShiftSeeder::class);
        $this->call(MilkBuyingPriceTypeSeeder::class);
        $this->call(MilkQaParameterSeeder::class);

        // ── 15. Withholding taxes ─────────────────────────────────────────────
        $this->command->info('  [15/18] Seeding withholding taxes...');
        $this->call(WithholdingTaxSeeder::class);

        // ── 16. Manufacturing types ───────────────────────────────────────────
        $this->command->info('  [16/18] Seeding manufacturing types...');
        $this->call(ManufacturingTypeSeeder::class);

        // ── 17. Casual worker trades ──────────────────────────────────────────
        $this->command->info('  [17/19] Seeding casual worker trades...');
        $this->call(CasualWorkerTradeSeeder::class);

        // ── 18. Passport personal-access client ───────────────────────────────
        $this->command->info('  [18/19] Ensuring Passport personal-access client exists...');
        $this->ensurePassportClient();

        $this->command->info('  [19/19] Done.');
        $this->command->info('');
        $this->command->info('✅ Fresh installation complete!');
        $this->command->info('');
        $this->command->line('  Admin login:  admin@verp.local / Admin@123456');
        $this->command->line('  Set SEED_SAMPLE_DATA=true to seed farmers, suppliers, customers & items.');
        $this->command->info('');
    }

    private function seedAdminUser(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        $superAdmin = Role::where('name', 'super_admin')->where('guard_name', 'api')->first();

        $admin = User::firstOrCreate(
            ['user_id' => 'admin'],
            [
                'email'         => 'admin@nexora.local',
                'password'      => Hash::make('Admin@123456'),
                'pin'           => Hash::make('1234'),
                'real_name'     => 'System Administrator',
                'phone'         => '',
                'language'      => 'en',
                'theme'         => 'light',
                'page_size'     => 'A4',
                'prices_dec'    => 2,
                'qty_dec'       => 2,
                'rates_dec'     => 4,
                'percent_dec'   => 1,
                'show_gl'       => true,
                'show_codes'    => false,
                'show_hints'    => false,
                'graphic_links' => true,
                'rep_popup'     => true,
                'startup_tab'   => 'dashboard',
                'transaction_days' => 30,
                'use_date_picker'  => true,
                'query_size'    => 100,
                'inactive'      => false,
            ]
        );

        if ($superAdmin) {
            DB::table('model_has_roles')->where('model_id', $admin->id)->delete();
            DB::table('model_has_roles')->insert([
                'role_id'    => $superAdmin->id,
                'model_type' => User::class,
                'model_id'   => $admin->id,
            ]);
        }
    }

    private function ensurePassportClient(): void
    {
        // Passport v13 uses grant_types column instead of personal_access_client boolean
        $exists = DB::table('oauth_clients')
            ->where('grant_types', 'like', '%personal_access%')
            ->exists();

        if (! $exists) {
            \Artisan::call('passport:client', [
                '--personal'       => true,
                '--name'           => 'Nexora Personal Access Client',
                '--no-interaction' => true,
            ]);
        }
    }
}
