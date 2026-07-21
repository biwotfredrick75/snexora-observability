<?php

namespace Tests\Feature\Farmers;

use App\Models\Farmer;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\MilkPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Accounting is posted on approve(), not on the initial store() submission —
 * see MilkPurchaseController::store()'s NOTE and MilkPurchaseApprovalService.
 * These tests exercise the whole submit → approve pipeline for a single,
 * office-approved ("web" source) batch and assert the money actually balances.
 */
class MilkPurchaseApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'user_id'        => 'tester',
            'password'       => bcrypt('secret'),
            'real_name'      => 'Tester',
            'phone'          => '0700000000',
            'pin'            => '0000',
            'print_profile'  => 'default',
            'startup_tab'    => 'dashboard',
            'default_store'  => 'MAIN',
        ]);
        $this->actingAs($user, 'api');

        // NB: MilkPurchaseApprovalService currently posts the payable side to
        // gl_settings.payable_account, not the dedicated farmers_payable_account
        // column that also exists on this table — a known naming inconsistency,
        // not something this test is asserting should change. Asserting against
        // payable_account here documents current behaviour.
        GlSetting::create([
            'items_inventory_account' => '150010',
            'grn_clearing_account'    => 'GRN-CLEAR',
            'payable_account'         => 'FARMERS-PAYABLE',
        ]);

        DB::table('items')->insert([
            'stock_id'          => 'RAWMILK',
            'description'       => 'RAW MILK',
            'units'             => 'litres',
            'inventory_account' => '150010',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('inventory_locations')->insert([
            'code' => 'MAIN', 'name' => 'Main Store', 'inactive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeRoute(): int
    {
        return DB::table('milk_routes')->insertGetId([
            'route_code' => 'R1', 'route_name' => 'Route 1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeShift(): int
    {
        return DB::table('milk_collection_shifts')->insertGetId([
            'description' => 'Morning', 'start_time' => '05:00', 'end_time' => '09:00', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeFarmerWithSupplier(string $memberNo): Farmer
    {
        $farmer = Farmer::create([
            'farmer_no' => 'FRM-' . $memberNo,
            'full_name' => 'Farmer ' . $memberNo,
            'member_no' => $memberNo,
            'status'    => 'active',
        ]);

        DB::table('suppliers')->insert([
            'supplierName'      => $farmer->full_name,
            'memberNumber'      => $memberNo,
            'routeGroupId'      => 1,
            'address'           => 'Nairobi',
            'supplierAddress'   => 'Nairobi',
            'currencyCode'      => 'KES',
            'notes'             => '',
            'supplierType'      => 'Standard',
            'gender'            => 'N/A',
            'dateOfBirth'       => '1990-01-01',
            'bankNumber'        => '',
            'bankBranch'        => '',
            'idNumber'          => '',
            'nextOfKin'         => '',
            'nextOfKinId'       => '',
            'nextOfKinRelationship' => '',
            'route'             => '',
            'packingSharesLimit'=> 0,
            'sharesLimit'       => 0,
            'balanceMessageDate'=> now()->toDateString(),
        ]);

        return $farmer;
    }

    public function test_batch_is_submitted_pending_and_not_auto_approved_for_office_entry(): void
    {
        $routeId = $this->makeRoute();
        $shiftId = $this->makeShift();
        $farmer  = $this->makeFarmerWithSupplier('MEM001');

        DB::table('milk_prices')->insert([
            'price_type' => 'normal', 'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(),
            'from_qty' => 0, 'to_qty' => 0, 'price' => 45,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->postJson('/api/farmers/milk-purchases', [
            'route_id'     => $routeId,
            'shift_id'     => $shiftId,
            'invoice_date' => now()->toDateString(),
            'items'        => [['farmer_id' => $farmer->id, 'quantity' => 10]],
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.status', 'submitted');
        $this->assertEqualsWithDelta(10.0, (float) $res->json('data.total_qty'), 0.0001);

        // No accounting posted yet.
        $this->assertSame(0, DB::table('gld_transactions')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_approving_a_batch_posts_balanced_gl_and_stock_for_each_farmer_line(): void
    {
        $routeId = $this->makeRoute();
        $shiftId = $this->makeShift();
        $farmerA = $this->makeFarmerWithSupplier('MEM001');
        $farmerB = $this->makeFarmerWithSupplier('MEM002');

        DB::table('milk_prices')->insert([
            'price_type' => 'normal', 'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(),
            'from_qty' => 0, 'to_qty' => 0, 'price' => 45,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $store = $this->postJson('/api/farmers/milk-purchases', [
            'route_id'     => $routeId,
            'shift_id'     => $shiftId,
            'invoice_date' => now()->toDateString(),
            'items'        => [
                ['farmer_id' => $farmerA->id, 'quantity' => 10],   // 10 * 45 = 450
                ['farmer_id' => $farmerB->id, 'quantity' => 5.5],  // 5.5 * 45 = 247.5
            ],
        ])->assertStatus(201);

        $purchaseId = $store->json('data.id');
        $this->assertSame(697.5, (float) $store->json('data.total_amount'));

        $approve = $this->postJson("/api/farmers/milk-purchases/{$purchaseId}/approve");
        $approve->assertStatus(200);
        $approve->assertJsonPath('data.status', 'approved');

        // ── Stock: RAW MILK moved in by the total quantity, once per line ──────
        $stockRows = DB::table('stock_movements')->where('stock_id', 'RAWMILK')->get();
        $this->assertCount(2, $stockRows);
        $this->assertEqualsWithDelta(15.5, $stockRows->sum('qty'), 0.0001);

        // ── GL: 4 entries per farmer line (DR Inventory / CR GRN Clear /
        //        CR Payable / DR GRN Clear), each set nets to zero ─────────────
        $glRows = GldTransaction::where('trans_no', $purchaseId)->get();
        $this->assertCount(8, $glRows, '4 GL entries per farmer line × 2 lines');
        $this->assertEqualsWithDelta(0.0, $glRows->sum('amount'), 0.0001, 'The whole batch\'s GL postings must net to zero.');

        $inventoryDebit = $glRows->where('account_code', '150010')->sum('amount');
        $this->assertEqualsWithDelta(697.5, $inventoryDebit, 0.0001, 'Inventory must be debited for the full batch value.');

        $payableCredit = $glRows->where('account_code', 'FARMERS-PAYABLE')->sum('amount');
        $this->assertEqualsWithDelta(-697.5, $payableCredit, 0.0001, 'Farmers payable must be credited for the full batch value.');

        // GRN clearing is used as a pass-through (receive side credits it,
        // invoice side debits it back) — must always net to zero regardless
        // of amounts.
        $grnClearingNet = $glRows->where('account_code', 'GRN-CLEAR')->sum('amount');
        $this->assertEqualsWithDelta(0.0, $grnClearingNet, 0.0001);

        // ── Supplier invoices (AP) recorded per farmer, summing to the batch ────
        $suppInvoiceTotal = DB::table('milk_supp_invoices')->where('purchase_id', $purchaseId)->sum('amount');
        $this->assertEqualsWithDelta(697.5, $suppInvoiceTotal, 0.0001);
    }

    public function test_approving_an_already_approved_batch_is_a_no_op_and_does_not_double_post(): void
    {
        $routeId = $this->makeRoute();
        $shiftId = $this->makeShift();
        $farmer  = $this->makeFarmerWithSupplier('MEM001');

        DB::table('milk_prices')->insert([
            'price_type' => 'normal', 'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(),
            'from_qty' => 0, 'to_qty' => 0, 'price' => 45,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $purchaseId = $this->postJson('/api/farmers/milk-purchases', [
            'route_id'     => $routeId,
            'shift_id'     => $shiftId,
            'invoice_date' => now()->toDateString(),
            'items'        => [['farmer_id' => $farmer->id, 'quantity' => 10]],
        ])->assertStatus(201)->json('data.id');

        $this->postJson("/api/farmers/milk-purchases/{$purchaseId}/approve")->assertStatus(200);
        $glCountAfterFirstApproval = GldTransaction::where('trans_no', $purchaseId)->count();

        $second = $this->postJson("/api/farmers/milk-purchases/{$purchaseId}/approve");
        $second->assertStatus(200);
        $second->assertJsonPath('message', 'Already approved');

        $this->assertSame(
            $glCountAfterFirstApproval,
            GldTransaction::where('trans_no', $purchaseId)->count(),
            'Re-approving an already-approved batch must not post accounting a second time.'
        );
    }
}
