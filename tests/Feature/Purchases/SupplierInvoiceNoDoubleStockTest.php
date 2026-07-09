<?php

namespace Tests\Feature\Purchases;

use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

class SupplierInvoiceNoDoubleStockTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(PurchaseOrderController $controller, string $method, PurchaseOrder $po, string $approver = 'tester'): void
    {
        $ref = new ReflectionClass($controller);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($controller, $po, $approver);
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

    private function makeItem(string $stockId): void
    {
        DB::table('items')->insert([
            'stock_id'          => $stockId,
            'description'       => 'Raw Milk',
            'units'             => 'litres',
            'inventory_account' => '150010',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_supplier_invoice_against_a_grn_does_not_double_count_stock(): void
    {
        GlSetting::create([
            'grn_clearing_account'       => 'GRN-CLEAR',
            'purchase_discount_account'  => '401037',
            'items_inventory_account'    => '150010',
        ]);

        $supplierId = $this->makeSupplier();
        $stockId    = 'MILK-RAW';
        $this->makeItem($stockId);

        // ── GRN: 100 litres received ────────────────────────────────────────
        $grn = PurchaseOrder::create([
            'po_no'        => 'GRN0001/2026',
            'type'         => 'grn',
            'supplier_id'  => $supplierId,
            'order_date'   => now()->toDateString(),
            'delivery_date'=> now()->toDateString(),
            'location_id'  => 'MAIN',
            'receive_into' => 'MAIN',
            'status'       => 'received',
        ]);
        $grnItem = $grn->items()->create([
            'stock_id'          => $stockId,
            'description'       => 'Raw Milk',
            'qty'               => 100,
            'unit'              => 'litres',
            'price_before_tax'  => 50,
            'discount_amt'      => 0,
        ]);

        $controller = new PurchaseOrderController();
        $this->invoke($controller, 'postGrnReceipt', $grn);

        // ── Supplier Invoice billed against that same GRN line (full qty) ───
        $invoice = PurchaseOrder::create([
            'po_no'         => 'INV0001/2026',
            'type'          => 'invoice',
            'supplier_id'   => $supplierId,
            'source_grn_id' => $grn->id,
            'order_date'    => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'location_id'   => 'MAIN',
            'receive_into'  => 'MAIN',
            'status'        => 'ceo_approved',
        ]);
        $invoice->items()->create([
            'grn_item_id'       => $grnItem->id,
            'stock_id'          => $stockId,
            'description'       => 'Raw Milk',
            'qty'               => 100,
            'unit'              => 'litres',
            'price_before_tax'  => 50,
            'discount_amt'      => 0,
        ]);

        $this->invoke($controller, 'postDirectInvoice', $invoice);

        // ── Assert: stock on hand only moved once, by the GRN quantity ──────
        $totalQtyIn = StockMovement::where('stock_id', $stockId)->sum('qty');
        $this->assertSame(100.0, (float) $totalQtyIn, 'Stock quantity must only be credited once, by the GRN receipt.');

        $movementCount = StockMovement::where('stock_id', $stockId)->count();
        $this->assertSame(1, $movementCount, 'Supplier invoice billed against an existing GRN must not create a second stock movement.');

        $invoiceMovements = StockMovement::where('trans_no', $invoice->id)->where('stock_id', $stockId)->count();
        $this->assertSame(0, $invoiceMovements, 'The GRN-linked supplier invoice must not create any stock movement of its own.');

        // ── Assert: GL settles GRN clearing, does not re-debit inventory ────
        $inventoryDebitsOnInvoice = GldTransaction::where('trans_no', $invoice->id)
            ->where('account_code', '150010')
            ->count();
        $this->assertSame(0, $inventoryDebitsOnInvoice, 'The invoice must not debit the inventory account again — that was already booked at GRN receipt.');

        $grnClearingDebit = GldTransaction::where('trans_no', $invoice->id)
            ->where('account_code', 'GRN-CLEAR')
            ->where('amount', '>', 0)
            ->exists();
        $this->assertTrue($grnClearingDebit, 'The invoice should debit GRN Clearing to settle the liability booked at receipt.');
    }

    public function test_true_direct_invoice_without_a_prior_grn_still_moves_stock(): void
    {
        GlSetting::create([
            'grn_clearing_account'       => 'GRN-CLEAR',
            'purchase_discount_account'  => '401037',
            'items_inventory_account'    => '150010',
        ]);

        $supplierId = $this->makeSupplier();
        $stockId    = 'MILK-RAW';
        $this->makeItem($stockId);

        $invoice = PurchaseOrder::create([
            'po_no'         => 'INV0002/2026',
            'type'          => 'invoice',
            'supplier_id'   => $supplierId,
            'order_date'    => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'location_id'   => 'MAIN',
            'receive_into'  => 'MAIN',
            'status'        => 'ceo_approved',
        ]);
        $invoice->items()->create([
            'stock_id'          => $stockId,
            'description'       => 'Raw Milk',
            'qty'               => 40,
            'unit'              => 'litres',
            'price_before_tax'  => 50,
            'discount_amt'      => 0,
        ]);

        $controller = new PurchaseOrderController();
        $this->invoke($controller, 'postDirectInvoice', $invoice);

        $totalQtyIn = StockMovement::where('stock_id', $stockId)->sum('qty');
        $this->assertSame(40.0, (float) $totalQtyIn, 'A true direct invoice (no prior GRN) must still move stock in.');
    }
}
