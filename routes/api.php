<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RolePermissionController;
use App\Http\Controllers\Auth\PassportAuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Setup\CompanyController;
use App\Http\Controllers\Setup\UserController;
use App\Http\Controllers\Setup\RoleController;
use App\Http\Controllers\Setup\DisplayController;
use App\Http\Controllers\Setup\TransactionRefController;
use App\Http\Controllers\Setup\GlSettingController;
use App\Http\Controllers\Setup\TaxTypeController;
use App\Http\Controllers\Setup\TaxGroupController;
use App\Http\Controllers\Setup\ItemTaxTypeController;
use App\Http\Controllers\Setup\WithholdingTaxController;
use App\Http\Controllers\Setup\FiscalYearController;
use App\Http\Controllers\Setup\PrintingProfileController;
use App\Http\Controllers\Banking\ChartOfAccountsController;
use App\Http\Controllers\Banking\GlAccountClassController;
use App\Http\Controllers\Banking\GlAccountGroupController;
use App\Http\Controllers\Banking\JournalInquiryController;
use App\Http\Controllers\Setup\PaymentTermController;
use App\Http\Controllers\Setup\FarmerPaymentTermController;
use App\Http\Controllers\Setup\ShippingCompanyController;
use App\Http\Controllers\Setup\PosSettingController;
use App\Http\Controllers\Setup\PrinterLocationController;
use App\Http\Controllers\Setup\ContactCategoryController;
use App\Http\Controllers\Setup\VoidTransactionController;
use App\Http\Controllers\Setup\ViewTransactionController;
use App\Http\Controllers\Setup\AttachDocumentController;
use App\Http\Controllers\Setup\BackupController;
use App\Http\Controllers\Setup\CompanyDatabaseController;
use App\Http\Controllers\Setup\SystemDiagnosticsController;
use App\Http\Controllers\Setup\DimensionController;
use App\Http\Controllers\Inventory\ItemCategoryController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ItemSalesPriceController;
use App\Http\Controllers\Inventory\ItemPurchasePriceController;
use App\Http\Controllers\Inventory\ItemSubcategoryController;
use App\Http\Controllers\Inventory\UnitOfMeasureController;
use App\Http\Controllers\Inventory\InventoryLocationController;
use App\Http\Controllers\Inventory\StoreAllocationController;
use App\Http\Controllers\Inventory\SalesKitController;
use App\Http\Controllers\Inventory\ItemConversionController;
use App\Http\Controllers\Inventory\PackagingTypeController;
use App\Http\Controllers\Inventory\PackagingQuantityController;
use App\Http\Controllers\Inventory\ReorderLevelController;
use App\Http\Controllers\Sales\SalesTypeController;
use App\Http\Controllers\Sales\SalesAreaController;
use App\Http\Controllers\Sales\SalesPersonController;
use App\Http\Controllers\Sales\SalesGroupController;
use App\Http\Controllers\Sales\CreditNoteReasonController;
use App\Http\Controllers\Sales\CreditNoteController;
use App\Http\Controllers\Sales\CustomerPaymentController;
use App\Http\Controllers\Sales\CustomerDepositController;
use App\Http\Controllers\Sales\MpesaController;
use App\Http\Controllers\Sales\ImportController;
use App\Http\Controllers\Sales\SalesDashboardController;
use App\Http\Controllers\Sales\CreditStatusController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Inventory\InventoryTransferController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\StockRequisitionController;
use App\Http\Controllers\Inventory\ConsumableIssueController;
use App\Http\Controllers\Inventory\StockTakeController;
use App\Http\Controllers\Inventory\PackagingTransferController;
use App\Http\Controllers\Inventory\PackagingReceiveController;
use App\Http\Controllers\Inventory\InventoryKpiController;
use App\Http\Controllers\Inventory\StockMovementInquiryController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Purchases\PurchaseRequisitionController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseQuotationController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\CustomerBranchController;
use App\Http\Controllers\Sales\CustomerContactController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesQuotationController;
use App\Http\Controllers\Sales\SalesInvoiceController;
use App\Http\Controllers\Sales\SalesDeliveryController;
use App\Http\Controllers\Farmers\FarmerKpiController;
use App\Http\Controllers\Farmers\FarmerBankController;
use App\Http\Controllers\Farmers\MilkGunController;
use App\Http\Controllers\Farmers\MilkStationController;
use App\Http\Controllers\Farmers\MilkRouteController;
use App\Http\Controllers\Farmers\MilkSessionController;
use App\Http\Controllers\Farmers\MilkShiftController;
use App\Http\Controllers\Farmers\AccountTransferSlipController;
use App\Http\Controllers\Farmers\MilkPriceController;
use App\Http\Controllers\Farmers\MilkPriceMemberController;
use App\Http\Controllers\Farmers\MilkBuyingPriceTypeController;
use App\Http\Controllers\Farmers\CheckoffServiceController;
use App\Http\Controllers\Farmers\MilkQaParameterController;
use App\Http\Controllers\Farmers\FarmerController;
use App\Http\Controllers\Farmers\FarmerContactController;
use App\Http\Controllers\Farmers\MilkPurchaseController;
use App\Http\Controllers\Farmers\MilkCollectionReportController;
use App\Http\Controllers\Farmers\GraderCollectionReportController;
use App\Http\Controllers\Farmers\FarmerPaymentController;
use App\Http\Controllers\Farmers\FarmerPaymentProcessController;
use App\Http\Controllers\Farmers\FarmerDirectInvoiceController;
use App\Http\Controllers\Farmers\MilkLocationTransferController;

/**
 * Authentication Routes
 * 
 * Public routes for authentication (login, register)
 * Protected routes for user management and role/permission assignment
 * OAuth2 routes for external system API access via Passport
 */
// ==========================================
// LEGACY SANCTUM AUTHENTICATION (Deprecated)
// Use Passport OAuth2 instead for new systems
// ==========================================
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

// ==========================================
// OAUTH2 / PASSPORT AUTHENTICATION
// For external systems and API integrations
// ==========================================

// Passport OAuth2 Password Grant Flow
// External systems use this to obtain access tokens
Route::prefix('oauth')->group(function () {
    // Issue token endpoint - used by external systems
    // POST /api/oauth/issue-token
    // Body: { user_id, password, scope (optional) }
    Route::post('issue-token', [PassportAuthController::class, 'issueToken'])->name('passport.issue-token');
});

// Passport protected routes - requires valid OAuth2 token
Route::middleware('auth:api')->group(function () {
    Route::prefix('oauth')->group(function () {
        // Get / update current authenticated user
        Route::get('profile',     [PassportAuthController::class, 'me']);
        Route::put('profile',     [ProfileController::class, 'update']);
        Route::put('password',    [ProfileController::class, 'changePassword']);
        Route::put('preferences', [ProfileController::class, 'preferences']);
        // Token management
        Route::get('tokens', [PassportAuthController::class, 'tokens']);
        Route::post('tokens/personal', [PassportAuthController::class, 'createPersonalToken']);
        Route::post('tokens/revoke', [PassportAuthController::class, 'revokeToken']);
        // Refresh access token
        Route::post('refresh-token', [PassportAuthController::class, 'refresh']);
        // Logout (revoke all tokens)
        Route::post('logout', [PassportAuthController::class, 'logout']);
    });

    // Role and Permission Management (requires specific permissions)
    Route::prefix('roles')->middleware('permission:view-roles')->group(function () {
        Route::get('/', [RolePermissionController::class, 'getAllRoles']);
        Route::get('{roleName}/permissions', [RolePermissionController::class, 'getRoleWithPermissions']);
        
        Route::post('/', [RolePermissionController::class, 'createRole'])->middleware('permission:create-roles');
        Route::delete('{roleName}', [RolePermissionController::class, 'deleteRole'])->middleware('permission:delete-roles');
    });

    Route::prefix('permissions')->middleware('permission:view-permissions')->group(function () {
        Route::get('/', [RolePermissionController::class, 'getAllPermissions']);
        
        Route::post('/', [RolePermissionController::class, 'createPermission'])->middleware('permission:create-permissions');
        Route::delete('{permissionName}', [RolePermissionController::class, 'deletePermission'])->middleware('permission:delete-permissions');
    });

    // Assign permissions to roles
    Route::post('roles/permissions/assign', [RolePermissionController::class, 'assignPermissionToRole'])
        ->middleware('permission:assign-roles');

    // ── Setup ─────────────────────────────────────────────────────────────────
    Route::prefix('setup')->group(function () {
        Route::get('company',  [CompanyController::class, 'show']);
        Route::post('company', [CompanyController::class, 'update']);

        Route::get('users',         [UserController::class, 'index']);
        Route::post('users',        [UserController::class, 'store']);
        Route::put('users/{id}',    [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        Route::get('roles',                    [RoleController::class, 'index']);
        Route::post('roles',                   [RoleController::class, 'store']);
        Route::delete('roles/{name}',          [RoleController::class, 'destroy']);
        Route::post('roles/{name}/sync',       [RoleController::class, 'sync']);
        Route::get('permissions',              [RoleController::class, 'allPermissions']);

        Route::get('display',  [DisplayController::class, 'show']);
        Route::post('display', [DisplayController::class, 'update']);

        Route::get('gl-settings',  [GlSettingController::class, 'show']);
        Route::post('gl-settings', [GlSettingController::class, 'update']);

        Route::get('transaction-refs',         [TransactionRefController::class, 'index']);
        Route::post('transaction-refs',        [TransactionRefController::class, 'store']);
        Route::put('transaction-refs/{id}',    [TransactionRefController::class, 'update']);
        Route::delete('transaction-refs/{id}', [TransactionRefController::class, 'destroy']);

        Route::get('tax-types',         [TaxTypeController::class, 'index']);
        Route::post('tax-types',        [TaxTypeController::class, 'store']);
        Route::put('tax-types/{id}',    [TaxTypeController::class, 'update']);
        Route::delete('tax-types/{id}', [TaxTypeController::class, 'destroy']);

        Route::get('tax-groups',         [TaxGroupController::class, 'index']);
        Route::post('tax-groups',        [TaxGroupController::class, 'store']);
        Route::put('tax-groups/{id}',    [TaxGroupController::class, 'update']);
        Route::delete('tax-groups/{id}', [TaxGroupController::class, 'destroy']);

        Route::get('item-tax-types',         [ItemTaxTypeController::class, 'index']);
        Route::post('item-tax-types',        [ItemTaxTypeController::class, 'store']);
        Route::put('item-tax-types/{id}',    [ItemTaxTypeController::class, 'update']);
        Route::delete('item-tax-types/{id}', [ItemTaxTypeController::class, 'destroy']);

        Route::get('withholding-taxes',         [WithholdingTaxController::class, 'index']);
        Route::post('withholding-taxes',        [WithholdingTaxController::class, 'store']);
        Route::put('withholding-taxes/{id}',    [WithholdingTaxController::class, 'update']);
        Route::delete('withholding-taxes/{id}', [WithholdingTaxController::class, 'destroy']);

        Route::get('fiscal-years',         [FiscalYearController::class, 'index']);
        Route::post('fiscal-years',        [FiscalYearController::class, 'store']);
        Route::put('fiscal-years/{id}',    [FiscalYearController::class, 'update']);
        Route::delete('fiscal-years/{id}', [FiscalYearController::class, 'destroy']);

        Route::get('printing-profiles',         [PrintingProfileController::class, 'index']);
        Route::get('printing-profiles/{id}',    [PrintingProfileController::class, 'show']);
        Route::post('printing-profiles',        [PrintingProfileController::class, 'store']);
        Route::put('printing-profiles/{id}',    [PrintingProfileController::class, 'update']);
        Route::delete('printing-profiles/{id}', [PrintingProfileController::class, 'destroy']);

        // ── Payment Terms ──────────────────────────────────────────────────────
        Route::get('payment-terms',         [PaymentTermController::class, 'index']);
        Route::post('payment-terms',        [PaymentTermController::class, 'store']);
        Route::put('payment-terms/{id}',    [PaymentTermController::class, 'update']);
        Route::delete('payment-terms/{id}', [PaymentTermController::class, 'destroy']);

        // ── Farmer Payment Terms ───────────────────────────────────────────────
        Route::get('farmer-payment-terms',         [FarmerPaymentTermController::class, 'index']);
        Route::post('farmer-payment-terms',        [FarmerPaymentTermController::class, 'store']);
        Route::put('farmer-payment-terms/{id}',    [FarmerPaymentTermController::class, 'update']);
        Route::delete('farmer-payment-terms/{id}', [FarmerPaymentTermController::class, 'destroy']);

        // ── Shipping Companies ─────────────────────────────────────────────────
        Route::get('shipping-companies',         [ShippingCompanyController::class, 'index']);
        Route::post('shipping-companies',        [ShippingCompanyController::class, 'store']);
        Route::put('shipping-companies/{id}',    [ShippingCompanyController::class, 'update']);
        Route::delete('shipping-companies/{id}', [ShippingCompanyController::class, 'destroy']);

        // ── POS Settings ───────────────────────────────────────────────────────
        Route::get('pos-settings',         [PosSettingController::class, 'index']);
        Route::post('pos-settings',        [PosSettingController::class, 'store']);
        Route::put('pos-settings/{id}',    [PosSettingController::class, 'update']);
        Route::delete('pos-settings/{id}', [PosSettingController::class, 'destroy']);

        // ── Printer Locations ──────────────────────────────────────────────────
        Route::get('printer-locations',         [PrinterLocationController::class, 'index']);
        Route::post('printer-locations',        [PrinterLocationController::class, 'store']);
        Route::put('printer-locations/{id}',    [PrinterLocationController::class, 'update']);
        Route::delete('printer-locations/{id}', [PrinterLocationController::class, 'destroy']);

        // ── Contact Categories ─────────────────────────────────────────────────
        Route::get('contact-categories',         [ContactCategoryController::class, 'index']);
        Route::post('contact-categories',        [ContactCategoryController::class, 'store']);
        Route::put('contact-categories/{id}',    [ContactCategoryController::class, 'update']);
        Route::delete('contact-categories/{id}', [ContactCategoryController::class, 'destroy']);

        // ── Void Transaction ───────────────────────────────────────────────────
        Route::get('void-transaction/search',  [VoidTransactionController::class, 'search']);
        Route::post('void-transaction/void',   [VoidTransactionController::class, 'void']);

        // ── View Transactions ──────────────────────────────────────────────────
        Route::get('view-transactions/search', [ViewTransactionController::class, 'search']);

        // ── Attach Documents ───────────────────────────────────────────────────
        Route::get('attach-documents',         [AttachDocumentController::class, 'index']);
        Route::post('attach-documents',        [AttachDocumentController::class, 'store']);
        Route::delete('attach-documents/{id}', [AttachDocumentController::class, 'destroy']);

        // ── Backup & Restore ───────────────────────────────────────────────────
        Route::get('backup',                    [BackupController::class, 'index']);
        Route::post('backup',                   [BackupController::class, 'create']);
        Route::get('backup/{filename}/download',[BackupController::class, 'download'])->where('filename', '.+');
        Route::post('backup/restore',           [BackupController::class, 'restore']);
        Route::delete('backup/{filename}',      [BackupController::class, 'destroy'])->where('filename', '.+');

        // ── Company Databases ──────────────────────────────────────────────────
        Route::get('company-databases',         [CompanyDatabaseController::class, 'index']);
        Route::post('company-databases',        [CompanyDatabaseController::class, 'store']);
        Route::put('company-databases/{id}',    [CompanyDatabaseController::class, 'update']);
        Route::delete('company-databases/{id}', [CompanyDatabaseController::class, 'destroy']);

        // ── System Diagnostics ─────────────────────────────────────────────────
        Route::get('system-diagnostics',        [SystemDiagnosticsController::class, 'index']);

        // ── Dimensions ──────────────────────────────────────────────────────────
        Route::get('dimensions/next-reference',  [DimensionController::class, 'nextReference']);
        Route::get('dimensions',                 [DimensionController::class, 'index']);
        Route::post('dimensions',                [DimensionController::class, 'store']);
        Route::put('dimensions/{id}',            [DimensionController::class, 'update']);
        Route::delete('dimensions/{id}',         [DimensionController::class, 'destroy']);
    });

    // ── Banking / GL ───────────────────────────────────────────────────────────
    Route::prefix('banking')->group(function () {
        Route::get('gl-accounts',          [ChartOfAccountsController::class, 'index']);
        Route::post('gl-accounts',         [ChartOfAccountsController::class, 'store']);
        Route::put('gl-accounts/{id}',     [ChartOfAccountsController::class, 'update']);
        Route::delete('gl-accounts/{id}',  [ChartOfAccountsController::class, 'destroy']);

        Route::get('gl-account-groups',    [ChartOfAccountsController::class, 'groups']);
        Route::post('gl-account-groups',   [ChartOfAccountsController::class, 'storeGroup']);

        // Journal Inquiry
        Route::get('journals',                          [JournalInquiryController::class, 'index']);
        Route::get('journals/types',                    [JournalInquiryController::class, 'types']);
        Route::get('journals/{type}/{transNo}/lines',   [JournalInquiryController::class, 'lines']);

        // GL Classes
        Route::get('gl-classes',           [GlAccountClassController::class, 'index']);
        Route::post('gl-classes',          [GlAccountClassController::class, 'store']);
        Route::put('gl-classes/{id}',      [GlAccountClassController::class, 'update']);
        Route::delete('gl-classes/{id}',   [GlAccountClassController::class, 'destroy']);

        // GL Groups (full CRUD with class + parent)
        Route::get('gl-groups',            [GlAccountGroupController::class, 'index']);
        Route::post('gl-groups',           [GlAccountGroupController::class, 'store']);
        Route::put('gl-groups/{id}',       [GlAccountGroupController::class, 'update']);
        Route::delete('gl-groups/{id}',    [GlAccountGroupController::class, 'destroy']);
    });

    // ── Inventory ──────────────────────────────────────────────────────────────
    Route::prefix('inventory')->group(function () {
        Route::get('kpis',                  [InventoryKpiController::class,         'index']);
        Route::post('check-availability',   [InventoryKpiController::class,         'checkAvailability']);
        Route::get('movements',  [StockMovementInquiryController::class, 'index']);

        // Item Categories
        Route::get('item-categories',          [ItemCategoryController::class, 'index']);
        Route::post('item-categories',         [ItemCategoryController::class, 'store']);
        Route::put('item-categories/{id}',     [ItemCategoryController::class, 'update']);
        Route::delete('item-categories/{id}',  [ItemCategoryController::class, 'destroy']);

        // Items
        Route::get('items/search',              [ItemController::class, 'search']);
        Route::get('items',                     [ItemController::class, 'index']);
        Route::get('items/{id}/status',         [ItemController::class, 'stockStatus']);
        Route::get('items/{id}/transactions',   [ItemController::class, 'transactions']);
        Route::get('items/{id}',                [ItemController::class, 'show']);
        Route::post('items',              [ItemController::class, 'store']);
        Route::post('items/{id}',         [ItemController::class, 'update']); // POST for multipart (image upload)
        Route::delete('items/{id}',       [ItemController::class, 'destroy']);

        // Item Sales Prices (nested)
        Route::get('items/{id}/sales-prices',              [ItemSalesPriceController::class, 'index']);
        Route::post('items/{id}/sales-prices',             [ItemSalesPriceController::class, 'store']);
        Route::put('items/{id}/sales-prices/{priceId}',    [ItemSalesPriceController::class, 'update']);
        Route::delete('items/{id}/sales-prices/{priceId}', [ItemSalesPriceController::class, 'destroy']);

        // Item Purchase Prices (nested)
        Route::get('items/{id}/purchase-prices',              [ItemPurchasePriceController::class, 'index']);
        Route::post('items/{id}/purchase-prices',             [ItemPurchasePriceController::class, 'store']);
        Route::put('items/{id}/purchase-prices/{priceId}',    [ItemPurchasePriceController::class, 'update']);
        Route::delete('items/{id}/purchase-prices/{priceId}', [ItemPurchasePriceController::class, 'destroy']);

        // Item Conversion (nested under items)
        Route::get('items/{itemId}/conversion-items',              [ItemConversionController::class, 'index']);
        Route::post('items/{itemId}/conversion-items',             [ItemConversionController::class, 'store']);
        Route::delete('items/{itemId}/conversion-items/{id}',      [ItemConversionController::class, 'destroy']);

        // Reorder Levels (nested under items)
        Route::get('items/{itemId}/reorder-levels',            [ReorderLevelController::class, 'index']);
        Route::put('items/{itemId}/reorder-levels/{levelId}',  [ReorderLevelController::class, 'update']);

        // Item Subcategories
        Route::get('item-subcategories',          [ItemSubcategoryController::class, 'index']);
        Route::post('item-subcategories',         [ItemSubcategoryController::class, 'store']);
        Route::put('item-subcategories/{id}',     [ItemSubcategoryController::class, 'update']);
        Route::delete('item-subcategories/{id}',  [ItemSubcategoryController::class, 'destroy']);

        // Units of Measure
        Route::get('units-of-measure',          [UnitOfMeasureController::class, 'index']);
        Route::post('units-of-measure',         [UnitOfMeasureController::class, 'store']);
        Route::put('units-of-measure/{id}',     [UnitOfMeasureController::class, 'update']);
        Route::delete('units-of-measure/{id}',  [UnitOfMeasureController::class, 'destroy']);

        // Inventory Locations
        Route::get('locations',          [InventoryLocationController::class, 'index']);
        Route::post('locations',         [InventoryLocationController::class, 'store']);
        Route::put('locations/{id}',     [InventoryLocationController::class, 'update']);
        Route::delete('locations/{id}',  [InventoryLocationController::class, 'destroy']);

        // Store Allocations
        Route::get('store-allocations',          [StoreAllocationController::class, 'index']);
        Route::post('store-allocations',         [StoreAllocationController::class, 'store']);
        Route::delete('store-allocations/{id}',  [StoreAllocationController::class, 'destroy']);

        // Sales Kits
        Route::get('sales-kits',          [SalesKitController::class, 'index']);
        Route::post('sales-kits',         [SalesKitController::class, 'store']);
        Route::put('sales-kits/{id}',     [SalesKitController::class, 'update']);
        Route::delete('sales-kits/{id}',  [SalesKitController::class, 'destroy']);
        Route::get('sales-kits/{kitId}/items',               [SalesKitController::class, 'kitItems']);
        Route::post('sales-kits/{kitId}/items',              [SalesKitController::class, 'addKitItem']);
        Route::delete('sales-kits/{kitId}/items/{itemId}',   [SalesKitController::class, 'removeKitItem']);

        // Packaging Types
        Route::get('packaging-types',          [PackagingTypeController::class, 'index']);
        Route::post('packaging-types',         [PackagingTypeController::class, 'store']);
        Route::put('packaging-types/{id}',     [PackagingTypeController::class, 'update']);
        Route::delete('packaging-types/{id}',  [PackagingTypeController::class, 'destroy']);

        // Packaging Quantities
        Route::get('packaging-quantities/summary',   [PackagingQuantityController::class, 'summary']);
        Route::get('packaging-quantities',            [PackagingQuantityController::class, 'index']);
        Route::post('packaging-quantities',           [PackagingQuantityController::class, 'store']);
        Route::delete('packaging-quantities/{id}',   [PackagingQuantityController::class, 'destroy']);

        // Inventory Location Transfers
        Route::get('transfers',                      [InventoryTransferController::class, 'index']);
        Route::post('transfers',                     [InventoryTransferController::class, 'store']);
        Route::get('transfers/{id}',                 [InventoryTransferController::class, 'show']);
        Route::put('transfers/{id}',                 [InventoryTransferController::class, 'update']);
        Route::post('transfers/{id}/submit',         [InventoryTransferController::class, 'submit']);
        Route::post('transfers/{id}/approve',        [InventoryTransferController::class, 'approve']);
        Route::post('transfers/{id}/reject',         [InventoryTransferController::class, 'reject']);
        Route::delete('transfers/{id}',              [InventoryTransferController::class, 'destroy']);

        // Inventory Adjustments
        Route::get('adjustments',                    [InventoryAdjustmentController::class, 'index']);
        Route::post('adjustments',                   [InventoryAdjustmentController::class, 'store']);
        Route::get('adjustments/{id}',               [InventoryAdjustmentController::class, 'show']);
        Route::put('adjustments/{id}',               [InventoryAdjustmentController::class, 'update']);
        Route::post('adjustments/{id}/process',      [InventoryAdjustmentController::class, 'process']);
        Route::delete('adjustments/{id}',            [InventoryAdjustmentController::class, 'destroy']);

        // Stock Requisitions
        Route::get('requisitions',                   [StockRequisitionController::class, 'index']);
        Route::post('requisitions',                  [StockRequisitionController::class, 'store']);
        Route::get('requisitions/{id}',              [StockRequisitionController::class, 'show']);
        Route::put('requisitions/{id}',              [StockRequisitionController::class, 'update']);
        Route::post('requisitions/{id}/submit',      [StockRequisitionController::class, 'submit']);
        Route::post('requisitions/{id}/approve',     [StockRequisitionController::class, 'approve']);
        Route::post('requisitions/{id}/reject',      [StockRequisitionController::class, 'reject']);
        Route::post('requisitions/{id}/dispatch',    [StockRequisitionController::class, 'dispatch']);
        Route::delete('requisitions/{id}',           [StockRequisitionController::class, 'destroy']);

        // Consumable Issues
        Route::get('consumable-issues',              [ConsumableIssueController::class, 'index']);
        Route::post('consumable-issues',             [ConsumableIssueController::class, 'store']);
        Route::get('consumable-issues/{id}',         [ConsumableIssueController::class, 'show']);
        Route::put('consumable-issues/{id}',         [ConsumableIssueController::class, 'update']);
        Route::post('consumable-issues/{id}/submit', [ConsumableIssueController::class, 'submit']);
        Route::post('consumable-issues/{id}/approve',[ConsumableIssueController::class, 'approve']);
        Route::post('consumable-issues/{id}/reject', [ConsumableIssueController::class, 'reject']);
        Route::delete('consumable-issues/{id}',      [ConsumableIssueController::class, 'destroy']);

        // Stock Takes
        Route::get('stock-takes',                    [StockTakeController::class, 'index']);
        Route::post('stock-takes',                   [StockTakeController::class, 'store']);
        Route::get('stock-takes/{id}',               [StockTakeController::class, 'show']);
        Route::post('stock-takes/{id}/submit',        [StockTakeController::class, 'submit']);
        Route::post('stock-takes/{id}/approve',       [StockTakeController::class, 'approve']);
        Route::delete('stock-takes/{id}',             [StockTakeController::class, 'destroy']);

        // Packaging Transfers
        Route::get('packaging-transfers',             [PackagingTransferController::class, 'index']);
        Route::post('packaging-transfers',            [PackagingTransferController::class, 'store']);
        Route::get('packaging-transfers/{id}',        [PackagingTransferController::class, 'show']);
        Route::post('packaging-transfers/{id}/process', [PackagingTransferController::class, 'process']);
        Route::delete('packaging-transfers/{id}',     [PackagingTransferController::class, 'destroy']);

        // Packaging Receives
        Route::get('packaging-receives',              [PackagingReceiveController::class, 'index']);
        Route::post('packaging-receives',             [PackagingReceiveController::class, 'store']);
        Route::get('packaging-receives/{id}',         [PackagingReceiveController::class, 'show']);
        Route::delete('packaging-receives/{id}',      [PackagingReceiveController::class, 'destroy']);
    });

    // ── Sales Maintenance ────────────────────────────────────────────────────
    Route::prefix('sales')->group(function () {
        Route::get('types',          [SalesTypeController::class, 'index']);
        Route::post('types',         [SalesTypeController::class, 'store']);
        Route::put('types/{id}',     [SalesTypeController::class, 'update']);
        Route::delete('types/{id}',  [SalesTypeController::class, 'destroy']);

        Route::get('areas',          [SalesAreaController::class, 'index']);
        Route::post('areas',         [SalesAreaController::class, 'store']);
        Route::put('areas/{id}',     [SalesAreaController::class, 'update']);
        Route::delete('areas/{id}',  [SalesAreaController::class, 'destroy']);

        Route::get('persons',          [SalesPersonController::class, 'index']);
        Route::post('persons',         [SalesPersonController::class, 'store']);
        Route::put('persons/{id}',     [SalesPersonController::class, 'update']);
        Route::delete('persons/{id}',  [SalesPersonController::class, 'destroy']);

        Route::get('groups',          [SalesGroupController::class, 'index']);
        Route::post('groups',         [SalesGroupController::class, 'store']);
        Route::put('groups/{id}',     [SalesGroupController::class, 'update']);
        Route::delete('groups/{id}',  [SalesGroupController::class, 'destroy']);

        Route::get('credit-note-reasons',          [CreditNoteReasonController::class, 'index']);
        Route::post('credit-note-reasons',         [CreditNoteReasonController::class, 'store']);
        Route::put('credit-note-reasons/{id}',     [CreditNoteReasonController::class, 'update']);
        Route::delete('credit-note-reasons/{id}',  [CreditNoteReasonController::class, 'destroy']);

        Route::get('credit-statuses',          [CreditStatusController::class, 'index']);
        Route::post('credit-statuses',         [CreditStatusController::class, 'store']);
        Route::put('credit-statuses/{id}',     [CreditStatusController::class, 'update']);
        Route::delete('credit-statuses/{id}',  [CreditStatusController::class, 'destroy']);

        // POS
        Route::get('pos/items',              [PosController::class, 'items']);
        Route::get('pos/categories',         [PosController::class, 'categories']);
        Route::get('pos/lookup',             [PosController::class, 'lookup']);
        Route::get('pos/search',             [PosController::class, 'search']);
        Route::get('pos/sales',              [PosController::class, 'index']);
        Route::post('pos/sales',             [PosController::class, 'store']);
        Route::get('pos/sales/{id}',         [PosController::class, 'show']);
        Route::post('pos/sales/{id}/void',   [PosController::class, 'void']);
    });

    // ── Purchases ─────────────────────────────────────────────────────────────
    Route::prefix('purchases')->group(function () {
        // Suppliers
        Route::get('suppliers',          [SupplierController::class, 'index']);
        Route::post('suppliers',         [SupplierController::class, 'store']);
        Route::get('suppliers/{id}',     [SupplierController::class, 'show']);
        Route::put('suppliers/{id}',     [SupplierController::class, 'update']);
        Route::delete('suppliers/{id}',  [SupplierController::class, 'destroy']);

        // Purchase Requisitions
        Route::get('requisitions',                              [PurchaseRequisitionController::class, 'index']);
        Route::post('requisitions',                             [PurchaseRequisitionController::class, 'store']);
        Route::get('requisitions/{id}',                         [PurchaseRequisitionController::class, 'show']);
        Route::put('requisitions/{id}',                         [PurchaseRequisitionController::class, 'update']);
        Route::delete('requisitions/{id}',                      [PurchaseRequisitionController::class, 'destroy']);
        Route::post('requisitions/{id}/submit',                 [PurchaseRequisitionController::class, 'submit']);
        Route::post('requisitions/{id}/hod-approve',            [PurchaseRequisitionController::class, 'hodApprove']);
        Route::post('requisitions/{id}/finance-approve',        [PurchaseRequisitionController::class, 'financeApprove']);
        Route::post('requisitions/{id}/ceo-approve',            [PurchaseRequisitionController::class, 'ceoApprove']);
        Route::post('requisitions/{id}/reject',                 [PurchaseRequisitionController::class, 'reject']);

        // Purchase Quotations (RFQ)
        Route::get('quotations',                                    [PurchaseQuotationController::class, 'index']);
        Route::post('quotations',                                   [PurchaseQuotationController::class, 'store']);
        Route::get('quotations/{id}',                               [PurchaseQuotationController::class, 'show']);
        Route::delete('quotations/{id}',                            [PurchaseQuotationController::class, 'destroy']);
        Route::post('quotations/{id}/dispatch',                     [PurchaseQuotationController::class, 'dispatch']);
        Route::post('quotations/{id}/receive-response',             [PurchaseQuotationController::class, 'receiveResponse']);
        Route::post('quotations/{id}/rank',                         [PurchaseQuotationController::class, 'rank']);
        Route::post('quotations/{id}/convert-to-po',                [PurchaseQuotationController::class, 'convertToPo']);

        // Purchase Orders (type: po | grn | invoice via ?type=)
        Route::get('orders',                                    [PurchaseOrderController::class, 'index']);
        Route::post('orders',                                   [PurchaseOrderController::class, 'store']);
        Route::get('orders/{id}',                               [PurchaseOrderController::class, 'show']);
        Route::put('orders/{id}',                               [PurchaseOrderController::class, 'update']);
        Route::delete('orders/{id}',                            [PurchaseOrderController::class, 'destroy']);
        Route::post('orders/{id}/submit',                       [PurchaseOrderController::class, 'submit']);
        Route::post('orders/{id}/hod-approve',                  [PurchaseOrderController::class, 'hodApprove']);
        Route::post('orders/{id}/finance-approve',              [PurchaseOrderController::class, 'financeApprove']);
        Route::post('orders/{id}/ceo-approve',                  [PurchaseOrderController::class, 'ceoApprove']);
        Route::post('orders/{id}/reject',                       [PurchaseOrderController::class, 'reject']);
    });

    // ── Sales Transactions ─────────────────────────────────────────────────

    // Customers — form-data MUST be before {id} to avoid route conflict
    Route::get('sales/customers/form-data',     [CustomerController::class, 'formData']);
    Route::get('sales/customers',               [CustomerController::class, 'index']);
    Route::post('sales/customers',              [CustomerController::class, 'store']);
    Route::get('sales/customers/by-farmer/{farmer_no}', [CustomerController::class, 'byFarmer']);
    Route::get('sales/customers/{id}',          [CustomerController::class, 'show']);
    Route::put('sales/customers/{id}',          [CustomerController::class, 'update']);
    Route::delete('sales/customers/{id}',       [CustomerController::class, 'destroy']);
    Route::get('sales/customers/{id}/branches',      [CustomerController::class, 'branches']);
    Route::get('sales/customers/{id}/transactions',  [CustomerController::class, 'transactions']);
    Route::get('sales/customers/{id}/orders',        [CustomerController::class, 'customerOrders']);
    // Inquiry endpoints
    Route::get('sales/inquiries/transactions',       [CustomerController::class, 'allTransactions']);
    Route::get('sales/inquiries/allocations',        [CustomerController::class, 'allocations']);

    // Customer Contacts CRUD
    Route::get('sales/customer-contacts',         [CustomerContactController::class, 'index']);
    Route::post('sales/customer-contacts',        [CustomerContactController::class, 'store']);
    Route::put('sales/customer-contacts/{id}',    [CustomerContactController::class, 'update']);
    Route::delete('sales/customer-contacts/{id}', [CustomerContactController::class, 'destroy']);

    // Customer Branches CRUD
    Route::get('sales/customer-branches',         [CustomerBranchController::class, 'index']);
    Route::post('sales/customer-branches',        [CustomerBranchController::class, 'store']);
    Route::put('sales/customer-branches/{id}',    [CustomerBranchController::class, 'update']);
    Route::delete('sales/customer-branches/{id}', [CustomerBranchController::class, 'destroy']);

    // Sales Quotations
    Route::get('sales/quotations/next-ref',               [SalesQuotationController::class, 'nextRef']);
    Route::get('sales/quotations',                         [SalesQuotationController::class, 'index']);
    Route::post('sales/quotations',                        [SalesQuotationController::class, 'store']);
    Route::get('sales/quotations/{id}',                    [SalesQuotationController::class, 'show']);
    Route::put('sales/quotations/{id}',                    [SalesQuotationController::class, 'update']);
    Route::post('sales/quotations/{id}/place',             [SalesQuotationController::class, 'place']);
    Route::post('sales/quotations/{id}/cancel',            [SalesQuotationController::class, 'cancel']);
    Route::post('sales/quotations/{id}/convert-to-order',  [SalesQuotationController::class, 'convertToOrder']);
    Route::post('sales/quotations/{id}/items',             [SalesQuotationController::class, 'addItem']);
    Route::put('sales/quotations/{id}/items/{itemId}',     [SalesQuotationController::class, 'updateItem']);
    Route::delete('sales/quotations/{id}/items/{itemId}',  [SalesQuotationController::class, 'removeItem']);

    // Sales Orders
    Route::get('sales/orders/next-ref',              [SalesOrderController::class, 'nextRef']);
    Route::get('sales/orders/outstanding',           [SalesOrderController::class, 'outstanding']);
    Route::get('sales/orders/{id}/for-delivery',     [SalesOrderController::class, 'forDelivery']);
    Route::get('sales/orders/{id}/detail',           [SalesOrderController::class, 'detail']);
    Route::post('sales/orders/{id}/dispatch',        [SalesOrderController::class, 'dispatchFromOrder']);
    Route::get('sales/orders',                        [SalesOrderController::class, 'index']);
    Route::post('sales/orders',                       [SalesOrderController::class, 'store']);
    Route::get('sales/orders/{id}',                   [SalesOrderController::class, 'show']);
    Route::put('sales/orders/{id}',                   [SalesOrderController::class, 'update']);
    Route::post('sales/orders/{id}/place',            [SalesOrderController::class, 'place']);
    Route::post('sales/orders/{id}/cancel',           [SalesOrderController::class, 'cancel']);
    Route::post('sales/orders/{id}/items',            [SalesOrderController::class, 'addItem']);
    Route::put('sales/orders/{id}/items/{itemId}',    [SalesOrderController::class, 'updateItem']);
    Route::delete('sales/orders/{id}/items/{itemId}', [SalesOrderController::class, 'removeItem']);

    // Sales Invoices (Direct)
    Route::get('sales/invoices/next-ref',               [SalesInvoiceController::class, 'nextRef']);
    Route::get('sales/invoices',                         [SalesInvoiceController::class, 'index']);
    Route::post('sales/invoices',                        [SalesInvoiceController::class, 'store']);
    Route::get('sales/invoices/{id}',                    [SalesInvoiceController::class, 'show']);
    Route::put('sales/invoices/{id}',                    [SalesInvoiceController::class, 'update']);
    Route::post('sales/invoices/{id}/place',             [SalesInvoiceController::class, 'place']);
    Route::post('sales/invoices/{id}/cancel',            [SalesInvoiceController::class, 'cancel']);
    Route::post('sales/invoices/{id}/items',             [SalesInvoiceController::class, 'addItem']);
    Route::put('sales/invoices/{id}/items/{itemId}',     [SalesInvoiceController::class, 'updateItem']);
    Route::delete('sales/invoices/{id}/items/{itemId}',  [SalesInvoiceController::class, 'removeItem']);
    Route::get('sales/invoices/{id}/gl-entries',         [SalesInvoiceController::class, 'glEntries']);
    Route::get('sales/invoices/{id}/allocations',        [SalesInvoiceController::class, 'allocations']);
    Route::post('sales/invoices/{id}/apply-payments',    [SalesInvoiceController::class, 'applyPayments']);

    // Reports
    Route::get('sales/reports/quantity-comparison', \App\Http\Controllers\Sales\SalesQuantityComparisonController::class);

    // Credit Notes
    Route::get('sales/credit-notes',                           [CreditNoteController::class, 'index']);
    Route::post('sales/credit-notes',                          [CreditNoteController::class, 'store']);
    Route::get('sales/credit-notes/unallocated',               [CreditNoteController::class, 'unallocated']);
    Route::get('sales/credit-notes/{id}',                      [CreditNoteController::class, 'show']);
    Route::post('sales/credit-notes/{id}/place',               [CreditNoteController::class, 'place']);
    Route::post('sales/credit-notes/{id}/cancel',              [CreditNoteController::class, 'cancel']);
    Route::post('sales/credit-notes/{id}/allocate-manual',     [CreditNoteController::class, 'allocateManual']);

    Route::get('sales/customer-payments/unpaid-invoices',  [CustomerPaymentController::class, 'unpaidInvoices']);
    Route::get('sales/customer-payments/unallocated',      [CustomerPaymentController::class, 'unallocated']);
    Route::post('sales/customer-payments/{id}/allocate-manual', [CustomerPaymentController::class, 'allocateManual']);
    Route::get('sales/customer-payments',                 [CustomerPaymentController::class, 'index']);
    Route::post('sales/customer-payments',                [CustomerPaymentController::class, 'store']);
    Route::get('sales/customer-payments/{id}',            [CustomerPaymentController::class, 'show']);
    Route::post('sales/customer-payments/{id}/cancel',    [CustomerPaymentController::class, 'cancel']);
    Route::post('sales/customer-payments/{id}/allocate',  [CustomerPaymentController::class, 'allocate']);

    Route::get('sales/customer-deposits/unallocated',     [CustomerDepositController::class, 'unallocated']);
    Route::get('sales/customer-deposits',                 [CustomerDepositController::class, 'index']);
    Route::post('sales/customer-deposits',                [CustomerDepositController::class, 'store']);
    Route::get('sales/customer-deposits/{id}',            [CustomerDepositController::class, 'show']);
    Route::post('sales/customer-deposits/{id}/allocate',  [CustomerDepositController::class, 'allocate']);
    Route::post('sales/customer-deposits/{id}/cancel',    [CustomerDepositController::class, 'cancel']);

    // M-Pesa
    Route::post('sales/import',                            [ImportController::class, 'import']);
    Route::get('sales/dashboard/kpis',                     [SalesDashboardController::class, 'kpis']);
    Route::get('sales/dashboard/store-items',              [SalesDashboardController::class, 'storeItems']);
    Route::get('sales/dashboard/milk-traceability',        [SalesDashboardController::class, 'milkTraceability']);
    Route::get('sales/dashboard/grader-collections',       [SalesDashboardController::class, 'graderCollections']);
    Route::get('sales/mpesa/tills',                       [MpesaController::class, 'tills']);
    Route::get('sales/mpesa/status',                      [MpesaController::class, 'checkStatus']);
    Route::get('sales/mpesa',                             [MpesaController::class, 'index']);
    Route::post('sales/mpesa',                            [MpesaController::class, 'store']);
    Route::post('sales/mpesa/{id}/transfer',              [MpesaController::class, 'transfer']);

    // Sales Deliveries (Direct)
    Route::get('sales/deliveries/{id}/gl-entries',        [SalesDeliveryController::class, 'glEntries']);
    Route::get('sales/deliveries/{id}/for-invoice',       [SalesDeliveryController::class, 'forInvoice']);
    Route::post('sales/deliveries/{id}/invoice',          [SalesDeliveryController::class, 'createInvoice']);
    Route::get('sales/deliveries/{id}/for-return',         [SalesDeliveryController::class, 'forReturn']);
    Route::post('sales/deliveries/{id}/return',             [SalesDeliveryController::class, 'processReturn']);
    Route::get('sales/deliveries/next-ref',               [SalesDeliveryController::class, 'nextRef']);
    Route::get('sales/deliveries',                         [SalesDeliveryController::class, 'index']);
    Route::post('sales/deliveries',                        [SalesDeliveryController::class, 'store']);
    Route::get('sales/deliveries/{id}',                    [SalesDeliveryController::class, 'show']);
    Route::put('sales/deliveries/{id}',                    [SalesDeliveryController::class, 'update']);
    Route::post('sales/deliveries/{id}/place',             [SalesDeliveryController::class, 'place']);
    Route::post('sales/deliveries/{id}/cancel',            [SalesDeliveryController::class, 'cancel']);
    Route::post('sales/deliveries/{id}/items',             [SalesDeliveryController::class, 'addItem']);
    Route::put('sales/deliveries/{id}/items/{itemId}',     [SalesDeliveryController::class, 'updateItem']);
    Route::delete('sales/deliveries/{id}/items/{itemId}',  [SalesDeliveryController::class, 'removeItem']);

    // ── Farmers Maintenance ───────────────────────────────────────────────────
    Route::prefix('farmers')->group(function () {
        Route::get('kpis',                      [FarmerKpiController::class,    'index']);

        Route::get('banks',                     [FarmerBankController::class, 'index']);
        Route::post('banks',                    [FarmerBankController::class, 'store']);
        Route::put('banks/{id}',                [FarmerBankController::class, 'update']);
        Route::delete('banks/{id}',             [FarmerBankController::class, 'destroy']);

        Route::get('milk-guns',                 [MilkGunController::class, 'index']);
        Route::post('milk-guns',                [MilkGunController::class, 'store']);
        Route::put('milk-guns/{id}',            [MilkGunController::class, 'update']);
        Route::delete('milk-guns/{id}',         [MilkGunController::class, 'destroy']);

        Route::get('milk-stations',             [MilkStationController::class, 'index']);
        Route::post('milk-stations',            [MilkStationController::class, 'store']);
        Route::put('milk-stations/{id}',        [MilkStationController::class, 'update']);
        Route::delete('milk-stations/{id}',     [MilkStationController::class, 'destroy']);

        Route::get('routes',                    [MilkRouteController::class, 'index']);
        Route::post('routes',                   [MilkRouteController::class, 'store']);
        Route::put('routes/{id}',               [MilkRouteController::class, 'update']);
        Route::delete('routes/{id}',            [MilkRouteController::class, 'destroy']);

        Route::get('sessions',                  [MilkSessionController::class, 'index']);
        Route::post('sessions',                 [MilkSessionController::class, 'store']);
        Route::put('sessions/{id}',             [MilkSessionController::class, 'update']);
        Route::delete('sessions/{id}',          [MilkSessionController::class, 'destroy']);

        Route::get('shifts',                    [MilkShiftController::class, 'index']);
        Route::post('shifts',                   [MilkShiftController::class, 'store']);
        Route::put('shifts/{id}',               [MilkShiftController::class, 'update']);
        Route::delete('shifts/{id}',            [MilkShiftController::class, 'destroy']);

        Route::get('transfer-slips',            [AccountTransferSlipController::class, 'index']);
        Route::post('transfer-slips',           [AccountTransferSlipController::class, 'store']);
        Route::get('transfer-slips/{id}',       [AccountTransferSlipController::class, 'show']);
        Route::put('transfer-slips/{id}',       [AccountTransferSlipController::class, 'update']);
        Route::delete('transfer-slips/{id}',    [AccountTransferSlipController::class, 'destroy']);

        Route::get('milk-prices',               [MilkPriceController::class, 'index']);
        Route::post('milk-prices',              [MilkPriceController::class, 'store']);
        Route::put('milk-prices/{id}',          [MilkPriceController::class, 'update']);
        Route::delete('milk-prices/{id}',       [MilkPriceController::class, 'destroy']);

        Route::get('milk-prices-per-member',    [MilkPriceMemberController::class, 'index']);
        Route::post('milk-prices-per-member',   [MilkPriceMemberController::class, 'store']);
        Route::put('milk-prices-per-member/{id}', [MilkPriceMemberController::class, 'update']);
        Route::delete('milk-prices-per-member/{id}', [MilkPriceMemberController::class, 'destroy']);

        Route::get('buying-price-types',        [MilkBuyingPriceTypeController::class, 'index']);
        Route::post('buying-price-types',       [MilkBuyingPriceTypeController::class, 'store']);
        Route::put('buying-price-types/{id}',   [MilkBuyingPriceTypeController::class, 'update']);
        Route::delete('buying-price-types/{id}',[MilkBuyingPriceTypeController::class, 'destroy']);

        Route::get('checkoff-services',         [CheckoffServiceController::class, 'index']);
        Route::post('checkoff-services',        [CheckoffServiceController::class, 'store']);
        Route::put('checkoff-services/{id}',    [CheckoffServiceController::class, 'update']);
        Route::delete('checkoff-services/{id}', [CheckoffServiceController::class, 'destroy']);

        Route::get('qa-parameters',             [MilkQaParameterController::class, 'index']);
        Route::post('qa-parameters',            [MilkQaParameterController::class, 'store']);
        Route::put('qa-parameters/{id}',        [MilkQaParameterController::class, 'update']);
        Route::delete('qa-parameters/{id}',     [MilkQaParameterController::class, 'destroy']);

        // Farmers (suppliers)
        Route::get('farmers/search',             [FarmerController::class, 'search']);
        Route::get('farmers/form-data',         [FarmerController::class, 'formData']);
        Route::get('farmers',                   [FarmerController::class, 'index']);
        Route::post('farmers',                  [FarmerController::class, 'store']);
        Route::get('farmers/{id}',              [FarmerController::class, 'show']);
        Route::put('farmers/{id}',              [FarmerController::class, 'update']);
        Route::delete('farmers/{id}',           [FarmerController::class, 'destroy']);
        Route::put('farmers/{id}/approve',      [FarmerController::class, 'approve']);

        // Farmer Contacts
        Route::get('farmer-contacts',           [FarmerContactController::class, 'index']);
        Route::post('farmer-contacts',          [FarmerContactController::class, 'store']);
        Route::put('farmer-contacts/{id}',      [FarmerContactController::class, 'update']);
        Route::delete('farmer-contacts/{id}',   [FarmerContactController::class, 'destroy']);

        // Bulk Milk Purchases
        Route::get('milk-purchases/form-data',             [MilkPurchaseController::class, 'formData']);
        Route::post('milk-purchases/reserve-reference',   [MilkPurchaseController::class, 'reserveReference']);
        Route::post('milk-purchases/bulk-approve',        [MilkPurchaseController::class, 'bulkApprove']);
        Route::post('milk-purchases/bulk-reject',         [MilkPurchaseController::class, 'bulkReject']);
        Route::delete('milk-purchases/draft/{id}',        [MilkPurchaseController::class, 'discardDraft']);
        Route::get('milk-purchases',                      [MilkPurchaseController::class, 'index']);
        Route::post('milk-purchases',                     [MilkPurchaseController::class, 'store']);
        Route::get('milk-purchases/{id}',                 [MilkPurchaseController::class, 'show']);
        Route::post('milk-purchases/{id}/approve',        [MilkPurchaseController::class, 'approve']);
        Route::post('milk-purchases/{id}/reject',         [MilkPurchaseController::class, 'reject']);

        // Milk Collection Report
        Route::get('milk-collection-report/form-data',    [MilkCollectionReportController::class, 'formData']);
        Route::get('milk-collection-report',              [MilkCollectionReportController::class, 'index']);
        Route::get('milk-collection-report/export/excel', [MilkCollectionReportController::class, 'exportExcel']);
        Route::get('milk-collection-report/export/pdf',   [MilkCollectionReportController::class, 'exportPdf']);

        // Grader Collection Report
        Route::get('grader-collection-report/form-data',    [GraderCollectionReportController::class, 'formData']);
        Route::get('grader-collection-report',              [GraderCollectionReportController::class, 'index']);
        Route::get('grader-collection-report/export/excel', [GraderCollectionReportController::class, 'exportExcel']);
        Route::get('grader-collection-report/export/pdf',   [GraderCollectionReportController::class, 'exportPdf']);

        Route::get('farmer-payment/form-data',   [FarmerPaymentController::class, 'formData']);
        Route::post('farmer-payment/load',        [FarmerPaymentController::class, 'loadPaymentData']);
        Route::post('farmer-payment/deductions',  [FarmerPaymentController::class, 'saveDeductions']);

        Route::get('farmer-payment/batch/check',      [FarmerPaymentProcessController::class, 'checkPeriod']);
        Route::post('farmer-payment/batch/initiate',  [FarmerPaymentProcessController::class, 'initiate']);
        Route::get('farmer-payment/batch/{id}/status', [FarmerPaymentProcessController::class, 'status']);
        Route::post('farmer-payment/batch/{id}/retry', [FarmerPaymentProcessController::class, 'retry']);
        Route::get('farmer-payment/batch/history',     [FarmerPaymentProcessController::class, 'history']);

        // Milk Location Transfers
        Route::get('milk-location-transfers/form-data',          [MilkLocationTransferController::class, 'formData']);
        Route::get('milk-location-transfers/available-quantity', [MilkLocationTransferController::class, 'availableQuantity']);
        Route::get('milk-location-transfers',                    [MilkLocationTransferController::class, 'index']);
        Route::post('milk-location-transfers',                   [MilkLocationTransferController::class, 'store']);

        // Direct Farmer Invoices
        Route::get('direct-invoices/form-data',          [FarmerDirectInvoiceController::class, 'formData']);
        Route::post('direct-invoices/reserve-reference', [FarmerDirectInvoiceController::class, 'reserveReference']);
        Route::delete('direct-invoices/draft/{id}',      [FarmerDirectInvoiceController::class, 'discardDraft']);
        Route::get('direct-invoices/farmer-info/{farmerId}', [FarmerDirectInvoiceController::class, 'farmerInfo']);
        Route::get('direct-invoices',                    [FarmerDirectInvoiceController::class, 'index']);
        Route::get('direct-invoices/{id}',               [FarmerDirectInvoiceController::class, 'show']);
        Route::put('direct-invoices/{id}',               [FarmerDirectInvoiceController::class, 'update']);
        Route::post('direct-invoices/{id}/place',        [FarmerDirectInvoiceController::class, 'place']);
        Route::post('direct-invoices/{id}/cancel',       [FarmerDirectInvoiceController::class, 'cancel']);
    });

    // ── Manufacturing ─────────────────────────────────────────────────────────
    Route::prefix('manufacturing')->group(function () {
        Route::get('kpis',                               [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'kpis']);
        Route::get('work-centres',                       [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'index']);
        Route::post('work-centres',                      [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'store']);
        Route::put('work-centres/{id}',                  [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'update']);
        Route::delete('work-centres/{id}',               [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'destroy']);
        Route::get('boms/items-search',                  [\App\Http\Controllers\Manufacturing\BomController::class,        'itemsSearch']);
        Route::get('boms',                               [\App\Http\Controllers\Manufacturing\BomController::class,        'index']);
        Route::post('boms',                              [\App\Http\Controllers\Manufacturing\BomController::class,        'store']);
        Route::get('boms/{id}',                          [\App\Http\Controllers\Manufacturing\BomController::class,        'show']);
        Route::put('boms/{id}',                          [\App\Http\Controllers\Manufacturing\BomController::class,        'update']);
        Route::delete('boms/{id}',                       [\App\Http\Controllers\Manufacturing\BomController::class,        'destroy']);
        Route::get('work-orders',                        [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'index']);
        Route::post('work-orders',                       [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'store']);
        Route::get('work-orders/{id}',                   [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'show']);
        Route::put('work-orders/{id}',                   [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'update']);
        Route::post('work-orders/{id}/release',          [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'release']);
        Route::post('work-orders/{id}/issue-all',        [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'issueAll']);
        Route::post('work-orders/{id}/complete',         [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'complete']);
        Route::post('work-orders/{id}/settle',           [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'settle']);
        Route::post('work-orders/{id}/labour',           [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'addLabour']);
        Route::post('work-orders/{id}/overhead',         [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'addOverhead']);
        Route::get('work-orders/{id}/cost-sheet',        [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'costSheet']);
        // Production Plans
        Route::get('production-locations',               [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'locations']);
        Route::get('items/stock-on-hand',                    [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'stockOnHand']);
        Route::get('production-plans',                       [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'index']);
        Route::post('production-plans',                      [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'store']);
        Route::get('production-plans/{id}',                  [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'show']);
        Route::put('production-plans/{id}',                  [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'update']);
        Route::delete('production-plans/{id}',               [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'destroy']);
        Route::post('production-plans/{id}/items',           [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'addItem']);
        Route::delete('production-plans/{id}/items/{itemId}',[\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'removeItem']);
        Route::post('production-plans/{id}/submit',          [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'submit']);
        Route::get('production-plans/{id}/approval-detail',     [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'approvalDetail']);
        Route::post('production-plans/{id}/supervisor-approve', [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'supervisorApprove']);
        Route::post('production-plans/{id}/approve',            [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'approve']);
        Route::post('production-plans/{id}/reject',             [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'reject']);
        Route::post('production-plans/{id}/execute',                        [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'execute']);
        Route::get('production-plans/{id}/execute-detail',                  [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'executeDetail']);
        Route::post('production-plans/{id}/items/{itemId}/create-wo',       [\App\Http\Controllers\Manufacturing\ProductionPlanController::class, 'createItemWorkOrder']);
    });
});
