<?php

namespace Tests\Feature\Purchases;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreditNoteInvoiceAllocationTest extends TestCase
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

    public function test_credit_note_can_be_allocated_against_an_invoice(): void
    {
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
            'amount_total' => 400,
        ]);

        $scnId = DB::table('supplier_credit_notes')->insertGetId([
            'scn_no'      => 'SCN0001/2026',
            'supplier_id' => $supplierId,
            'date'        => now()->toDateString(),
            'items_total' => 40,
            'gl_total'    => 0,
            'total'       => 40,
            'status'      => 'posted',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // ── The "available credit notes" list (feeds the new Credits tab) ────
        $available = $this->getJson('/api/purchases/available-credit-notes?supplier_id=' . $supplierId);
        $available->assertOk();
        $this->assertSame(40.0, (float) collect($available->json('data'))->firstWhere('id', $scnId)['available']);

        // ── Allocate the full credit note against the invoice ────────────────
        $alloc = $this->postJson('/api/purchases/supplier-credit-note-allocations', [
            'scn_id'           => $scnId,
            'supplier_id'      => $supplierId,
            'transaction_type' => 'Supplier Invoice',
            'transaction_id'   => $invoice->id,
            'amount'           => 40,
        ]);
        $alloc->assertCreated();

        // ── Credit note is now fully consumed — no longer "available" ────────
        $availableAfter = $this->getJson('/api/purchases/available-credit-notes?supplier_id=' . $supplierId);
        $availableAfter->assertOk();
        $this->assertNull(collect($availableAfter->json('data'))->firstWhere('id', $scnId));

        // ── Over-allocating beyond the credit note's balance is rejected ─────
        $over = $this->postJson('/api/purchases/supplier-credit-note-allocations', [
            'scn_id'           => $scnId,
            'supplier_id'      => $supplierId,
            'transaction_type' => 'Supplier Invoice',
            'transaction_id'   => $invoice->id,
            'amount'           => 1,
        ]);
        $over->assertStatus(422);

        $this->assertSame(40.0, (float) DB::table('supplier_credit_note_allocations')
            ->where('scn_id', $scnId)->sum('amount'));
    }

    public function test_post_voucher_payment_excludes_credit_notes_and_nets_the_contra(): void
    {
        $supplierId = $this->makeSupplier();

        $invoice = PurchaseOrder::create([
            'po_no'        => 'INV0002/2026',
            'type'         => 'invoice',
            'supplier_id'  => $supplierId,
            'order_date'   => now()->toDateString(),
            'delivery_date'=> now()->toDateString(),
            'location_id'  => 'MAIN',
            'receive_into' => 'MAIN',
            'status'       => 'received',
            'amount_total' => 400,
        ]);

        $scnId = DB::table('supplier_credit_notes')->insertGetId([
            'scn_no'      => 'SCN0002/2026',
            'supplier_id' => $supplierId,
            'date'        => now()->toDateString(),
            'items_total' => 40,
            'gl_total'    => 0,
            'total'       => 40,
            'status'      => 'posted',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Simulate a credit note already contra-applied to the invoice via the
        // Normal Supplier Inquiry screen (the GL-neutral path).
        DB::table('supplier_credit_note_allocations')->insert([
            'scn_id'           => $scnId,
            'transaction_type' => 'Supplier Invoice',
            'transaction_id'   => $invoice->id,
            'supplier_id'      => $supplierId,
            'amount'           => 40,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // ── Post Voucher Payment's open-transactions must not list the credit
        // note at all, and must show the invoice's balance net of the contra.
        $open = $this->getJson('/api/purchases/payment-vouchers/open-transactions?supplier_id=' . $supplierId);
        $open->assertOk();
        $rows = collect($open->json('data'));

        $this->assertNull($rows->firstWhere('transaction_type', 'Credit Note'), 'Credit notes must not appear in the cash-payment allocation table.');

        $invoiceRow = $rows->firstWhere('id', $invoice->id);
        $this->assertNotNull($invoiceRow);
        $this->assertSame(360.0, (float) $invoiceRow['left_to_allocate'], 'Invoice balance must be net of the credit note already contra-applied.');

        // ── Attempting to create a cash voucher allocation against a Credit
        // Note is rejected at the API boundary too, not just hidden in the UI.
        $store = $this->postJson('/api/purchases/payment-vouchers', [
            'supplier_id'       => $supplierId,
            'date_paid'         => now()->toDateString(),
            'amount'            => 40,
            'allocations'       => [
                ['transaction_type' => 'Credit Note', 'transaction_id' => $scnId, 'this_allocation' => 40],
            ],
        ]);
        $store->assertStatus(422);
    }
}
