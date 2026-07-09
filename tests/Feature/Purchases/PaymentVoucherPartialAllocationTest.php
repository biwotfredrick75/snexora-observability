<?php

namespace Tests\Feature\Purchases;

use App\Models\GlSetting;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentVoucherPartialAllocationTest extends TestCase
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
    }

    private function makeSupplier(): int
    {
        return DB::table('suppliers')->insertGetId([
            'supplierName'      => 'Test Supplier',
            'memberNumber'      => 'SUP-' . uniqid(),
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
    }

    public function test_underallocated_voucher_posts_the_remainder_as_a_supplier_advance(): void
    {
        GlSetting::create(['payable_account' => '201010']);

        $supplierId = $this->makeSupplier();

        $invoice = PurchaseOrder::create([
            'po_no'        => 'INV0001/2026',
            'type'         => 'invoice',
            'supplier_id'  => $supplierId,
            'order_date'   => now()->toDateString(),
            'delivery_date'=> now()->toDateString(),
            'location_id'  => 'MAIN',
            'receive_into' => 'MAIN',
            'status'       => 'received',
            'amount_total' => 320,
        ]);

        // Voucher for 3,000 — far more than the 320 actually owed.
        $store = $this->postJson('/api/purchases/payment-vouchers', [
            'supplier_id'  => $supplierId,
            'date_paid'    => now()->toDateString(),
            'amount'       => 3000,
            'allocations'  => [
                ['transaction_type' => 'Supplier Invoice', 'transaction_id' => $invoice->id, 'this_allocation' => 320],
            ],
        ]);
        $store->assertCreated();
        $voucherId = $store->json('data.id');

        $post = $this->postJson("/api/purchases/payment-vouchers/{$voucherId}/post");
        $post->assertOk();

        $this->assertSame('posted', DB::table('payment_vouchers')->where('id', $voucherId)->value('status'));

        // DR AP for the 320 allocated against the invoice.
        $apDebit = DB::table('gld_transactions')
            ->where('trans_no', $voucherId)->where('type', 22)
            ->where('account_code', '201010')->where('amount', 320)->exists();
        $this->assertTrue($apDebit, 'Expected a 320 debit to Accounts Payable for the allocated invoice.');

        // DR Supplier Advance for the 2,680 remainder.
        $advanceDebit = DB::table('gld_transactions')
            ->where('trans_no', $voucherId)->where('type', 22)
            ->where('amount', 2680)->exists();
        $this->assertTrue($advanceDebit, 'Expected a 2,680 debit parked as a Supplier Advance.');

        // CR Bank for the full 3,000 cash outflow.
        $bankCredit = DB::table('gld_transactions')
            ->where('trans_no', $voucherId)->where('type', 22)
            ->where('amount', -3000)->exists();
        $this->assertTrue($bankCredit, 'Expected the full 3,000 to be credited from the bank account.');

        // The GL batch must balance: debits + credits = 0.
        $sum = (float) DB::table('gld_transactions')->where('trans_no', $voucherId)->where('type', 22)->sum('amount');
        $this->assertEqualsWithDelta(0.0, $sum, 0.001, 'GL batch for the voucher must balance to zero.');
    }
}
