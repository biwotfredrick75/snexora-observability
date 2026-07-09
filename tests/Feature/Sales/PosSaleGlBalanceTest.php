<?php

namespace Tests\Feature\Sales;

use App\Models\GlSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleGlBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'user_id'        => 'admin',
            'password'       => bcrypt('secret'),
            'real_name'      => 'Admin',
            'phone'          => '0700000000',
            'pin'            => '0000',
            'print_profile'  => 'default',
            'startup_tab'    => 'dashboard',
            'default_store'  => 'MAIN',
        ]);

        $this->actingAs($user, 'api');

        GlSetting::create(['allow_negative_inventory' => true]);

        DB::table('inventory_locations')->insert([
            'code' => 'MAIN', 'name' => 'Main Store', 'inactive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeItem(string $stockId, int $taxTypeId = 0): void
    {
        DB::table('items')->insert([
            'stock_id'          => $stockId,
            'description'       => 'A4 Paper Ream 80gsm',
            'units'             => 'ream',
            'sales_account'     => '401035',
            'cogs_account'      => '501010',
            'inventory_account' => '103039',
            'tax_type_id'       => $taxTypeId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function assertGlBatchBalances(int $saleId): void
    {
        $sum = (float) DB::table('gld_transactions')
            ->where('trans_no', $saleId)->where('type', 26)
            ->sum('amount');
        $this->assertEqualsWithDelta(0.0, $sum, 0.001, 'POS sale GL batch must balance to zero.');
    }

    public function test_pos_sale_balances_when_item_has_no_tax_gl_account_configured(): void
    {
        // Matches the historical bug: item carries a tax_rate but has no
        // tax_type_id at all, so there's no GL account to post VAT to.
        $this->makeItem('OFFICE-A4');

        $res = $this->postJson('/api/sales/pos/sales', [
            'payment_method'  => 'cash',
            'amount_tendered' => 754,
            'items' => [
                ['stock_id' => 'OFFICE-A4', 'description' => 'A4 Paper Ream 80gsm', 'unit_price' => 650, 'quantity' => 1, 'tax_rate' => 16],
            ],
        ]);
        $res->assertCreated();
        $saleId = $res->json('data.id');

        // Cash must reflect what was actually collected (650 + 16% VAT = 754),
        // not just the pre-tax gross — even though tax has nowhere else to post.
        $cash = (float) DB::table('gld_transactions')
            ->where('trans_no', $saleId)->where('type', 26)
            ->where('account_code', 'CASH')->sum('amount');
        $this->assertSame(754.0, $cash);

        $this->assertGlBatchBalances($saleId);
    }

    public function test_pos_sale_balances_when_tax_gl_account_is_configured(): void
    {
        $taxTypeId = DB::table('tax_types')->insertGetId([
            'description'      => 'VAT 16%',
            'default_rate'     => 16,
            'sales_gl_account' => 'VAT-PAYABLE',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        $this->makeItem('OFFICE-A4', $taxTypeId);

        $res = $this->postJson('/api/sales/pos/sales', [
            'payment_method'  => 'cash',
            'amount_tendered' => 754,
            'items' => [
                ['stock_id' => 'OFFICE-A4', 'description' => 'A4 Paper Ream 80gsm', 'unit_price' => 650, 'quantity' => 1, 'tax_rate' => 16],
            ],
        ]);
        $res->assertCreated();
        $saleId = $res->json('data.id');

        $revenue = (float) DB::table('gld_transactions')
            ->where('trans_no', $saleId)->where('type', 26)
            ->where('account_code', '401035')->sum('amount');
        $this->assertSame(-650.0, $revenue, 'Revenue must be the tax-exclusive net (650), not net-minus-tax again.');

        $vat = (float) DB::table('gld_transactions')
            ->where('trans_no', $saleId)->where('type', 26)
            ->where('account_code', 'VAT-PAYABLE')->sum('amount');
        $this->assertSame(-104.0, $vat);

        $cash = (float) DB::table('gld_transactions')
            ->where('trans_no', $saleId)->where('type', 26)
            ->where('account_code', 'CASH')->sum('amount');
        $this->assertSame(754.0, $cash);

        $this->assertGlBatchBalances($saleId);
    }
}
