<?php
use App\Http\Controllers\Internal\AlertWebhookController;
use App\Http\Controllers\Internal\BulkApprovalCallbackController;
use App\Http\Controllers\Payroll\PayrollController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Analytics\MilkForecastController;
use App\Http\Controllers\Analytics\SalesAnalyticsController;
use App\Http\Controllers\Analytics\InventoryAnalyticsController;
use App\Http\Controllers\Analytics\ManufacturingAnalyticsController;
use App\Http\Controllers\Analytics\PurchasesAnalyticsController;
use App\Http\Controllers\Analytics\AiChatController;
use App\Http\Controllers\Analytics\FinancialAnalyticsController;
use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Esp\EspController;
use App\Http\Controllers\Sacco\SaccoController;
use App\Http\Controllers\Setup\EtimsSetupController;
use App\Http\Controllers\Etims\EtimsController;
use App\Http\Controllers\Etims\EtimsSettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GraderAuthController;
use App\Http\Controllers\Auth\RolePermissionController;
use App\Http\Controllers\Auth\PassportAuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Setup\CompanyController;
use App\Http\Controllers\Setup\DataResetController;
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
use App\Http\Controllers\Setup\VehicleController;
use App\Http\Controllers\Setup\DriverController;
use App\Http\Controllers\Setup\AppModuleController;
// ── Transport ──
use App\Http\Controllers\Transport\TransportRouteController;
use App\Http\Controllers\Transport\LoadingOrderController;
use App\Http\Controllers\Transport\FuelEntryController;
use App\Http\Controllers\Transport\MaintenanceRecordController;
use App\Http\Controllers\Transport\TransportDashboardController;
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
use App\Http\Controllers\Inventory\PackConversionController;
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
use App\Http\Controllers\Sales\PaymentChannelController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\ImportController;
use App\Http\Controllers\Sales\SalesDashboardController;
use App\Http\Controllers\Sales\CreditStatusController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Inventory\InventoryTransferController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\AdjustmentReasonController;
use App\Http\Controllers\Inventory\StockRequisitionController;
use App\Http\Controllers\Inventory\ConsumableIssueController;
use App\Http\Controllers\Inventory\StockTakeController;
use App\Http\Controllers\Inventory\PackagingTransferController;
use App\Http\Controllers\Inventory\PackagingReceiveController;
use App\Http\Controllers\Inventory\InventoryKpiController;
use App\Http\Controllers\Inventory\StockMovementInquiryController;
use App\Http\Controllers\Inventory\InventoryReportController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Purchases\PurchaseRequisitionController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseQuotationController;
use App\Http\Controllers\Purchases\SupplierCreditNoteController;
use App\Http\Controllers\Purchases\SupplierDebitNoteController;
use App\Http\Controllers\Purchases\SupplierAllocationController;
use App\Http\Controllers\Purchases\SupplierImportController;
use App\Http\Controllers\Purchases\SupplierPurchaseImportController;
use App\Http\Controllers\Purchases\PaymentVoucherController;
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
use App\Http\Controllers\Farmers\MilkQualitySettingController;
use App\Http\Controllers\Farmers\FarmerController;
use App\Http\Controllers\Farmers\FarmerContactController;
use App\Http\Controllers\Farmers\MilkPurchaseController;
use App\Http\Controllers\Farmers\MilkCollectionReportController;
use App\Http\Controllers\Farmers\GraderCollectionReportController;
use App\Http\Controllers\Farmers\FarmerPaymentController;
use App\Http\Controllers\Farmers\FarmerPaymentProcessController;
use App\Http\Controllers\Farmers\FarmerDirectInvoiceController;
use App\Http\Controllers\Farmers\MilkLocationTransferController;
use App\Http\Controllers\Farmers\FarmerSupplierPaymentController;
use App\Http\Controllers\Farmers\SupplierListController;
use App\Http\Controllers\Farmers\FarmerAdvanceReportController;
use App\Http\Controllers\Farmers\FarmersSpillageController;
use App\Http\Controllers\Farmers\GraderPayrollController;
use App\Http\Controllers\Farmers\ImportServicesController;

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
// FLUTTER / MOBILE — Grader Authentication
// Field workers (graders/milkmen) log in
// using their loc_code instead of email.
// ==========================================
Route::prefix('grader')->group(function () {
    Route::post('login', [GraderAuthController::class, 'login']);
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('grader')->group(function () {
        Route::post('logout', [GraderAuthController::class, 'logout']);
    });
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

        Route::middleware('role:super_admin')->group(function () {
            Route::get('data-reset/counts', [DataResetController::class, 'counts']);
            Route::post('data-reset/clear', [DataResetController::class, 'clear']);
        });

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

        // ── Activity Log (audit trail) ──────────────────────────────────────────
        Route::get('activity-log/actions', [\App\Http\Controllers\Setup\ActivityLogController::class, 'actions']);
        Route::get('activity-log',         [\App\Http\Controllers\Setup\ActivityLogController::class, 'index']);

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

        // ── Vehicles ────────────────────────────────────────────────────────────
        Route::get('vehicles',         [VehicleController::class, 'index']);
        Route::post('vehicles',        [VehicleController::class, 'store']);
        Route::put('vehicles/{id}',    [VehicleController::class, 'update']);
        Route::delete('vehicles/{id}', [VehicleController::class, 'destroy']);

        // ── Drivers ─────────────────────────────────────────────────────────────
        Route::get('drivers',          [DriverController::class, 'index']);
        Route::post('drivers',         [DriverController::class, 'store']);
        Route::put('drivers/{id}',     [DriverController::class, 'update']);
        Route::delete('drivers/{id}',  [DriverController::class, 'destroy']);

        Route::get('app-modules',              [AppModuleController::class, 'index']);
        Route::put('app-modules/{moduleId}',   [AppModuleController::class, 'update']);
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
        Route::get('journals/batch-lines',              [JournalInquiryController::class, 'batchLines']);
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

        // ── Journal Entries ───────────────────────────────────────────────────
        Route::get('journal-entries',                     [\App\Http\Controllers\Banking\JournalEntryController::class, 'index']);
        Route::post('journal-entries',                    [\App\Http\Controllers\Banking\JournalEntryController::class, 'store']);
        Route::get('journal-entries/pending-approval',    [\App\Http\Controllers\Banking\JournalEntryController::class, 'pendingApproval']);
        Route::get('journal-entries/pending-post',        [\App\Http\Controllers\Banking\JournalEntryController::class, 'pendingPost']);
        Route::post('journal-entries/post-bulk',          [\App\Http\Controllers\Banking\JournalEntryController::class, 'postBulk']);
        Route::get('journal-entries/{id}',                [\App\Http\Controllers\Banking\JournalEntryController::class, 'show']);
        Route::post('journal-entries/{id}/void',          [\App\Http\Controllers\Banking\JournalEntryController::class, 'void']);
        Route::post('journal-entries/{id}/approve',       [\App\Http\Controllers\Banking\JournalEntryController::class, 'approve']);
        Route::post('journal-entries/{id}/reject',        [\App\Http\Controllers\Banking\JournalEntryController::class, 'reject']);
        Route::post('journal-entries/{id}/post',          [\App\Http\Controllers\Banking\JournalEntryController::class, 'post']);

        // ── Recurring Journals ────────────────────────────────────────────────
        Route::get('recurring-journals',                  [\App\Http\Controllers\Banking\RecurringJournalController::class, 'index']);
        Route::post('recurring-journals',                 [\App\Http\Controllers\Banking\RecurringJournalController::class, 'store']);
        Route::post('recurring-journals/generate-due',    [\App\Http\Controllers\Banking\RecurringJournalController::class, 'generateDue']);
        Route::get('recurring-journals/{id}',              [\App\Http\Controllers\Banking\RecurringJournalController::class, 'show']);
        Route::put('recurring-journals/{id}',              [\App\Http\Controllers\Banking\RecurringJournalController::class, 'update']);
        Route::delete('recurring-journals/{id}',           [\App\Http\Controllers\Banking\RecurringJournalController::class, 'destroy']);

        // ── GL Inquiry ────────────────────────────────────────────────────────
        Route::get('gl-inquiry',                   [\App\Http\Controllers\Banking\GlInquiryController::class, 'index']);
        Route::get('gl-inquiry/type-labels',       [\App\Http\Controllers\Banking\GlInquiryController::class, 'typeLabels']);
        Route::get('gl-inquiry/transaction',       [\App\Http\Controllers\Banking\GlInquiryController::class, 'transaction']);

        // ── Bank Statement Import ─────────────────────────────────────────────
        Route::get('statement-imports/form-data',   [\App\Http\Controllers\Banking\BankStatementImportController::class, 'formData']);
        Route::post('statement-imports/parse',      [\App\Http\Controllers\Banking\BankStatementImportController::class, 'parse']);
        Route::post('statement-imports',            [\App\Http\Controllers\Banking\BankStatementImportController::class, 'store']);
        Route::get('statement-imports',             [\App\Http\Controllers\Banking\BankStatementImportController::class, 'index']);
        Route::get('statement-imports/{id}/lines',  [\App\Http\Controllers\Banking\BankStatementImportController::class, 'lines']);
        Route::post('statement-imports/match',      [\App\Http\Controllers\Banking\BankStatementImportController::class, 'match']);
        Route::post('statement-imports/unmatch',    [\App\Http\Controllers\Banking\BankStatementImportController::class, 'unmatch']);
        Route::get('statement-imports/gl-candidates', [\App\Http\Controllers\Banking\BankStatementImportController::class, 'glCandidates']);

        // ── Bank Reconciliation ───────────────────────────────────────────────
        Route::get('reconciliation/transactions',  [\App\Http\Controllers\Banking\BankReconciliationController::class, 'transactions']);
        Route::post('reconciliation/toggle',       [\App\Http\Controllers\Banking\BankReconciliationController::class, 'toggle']);
        Route::post('reconciliation/update-balance',[\App\Http\Controllers\Banking\BankReconciliationController::class, 'updateBalance']);
        Route::post('reconciliation/finalise',     [\App\Http\Controllers\Banking\BankReconciliationController::class, 'finalise']);
        Route::get('reconciliation/history',       [\App\Http\Controllers\Banking\BankReconciliationController::class, 'history']);

        // ── Bank Transactions ─────────────────────────────────────────────────
        Route::get('transactions/form-data',           [\App\Http\Controllers\Banking\BankTransactionController::class, 'formData']);
        Route::get('transactions/account-balance',     [\App\Http\Controllers\Banking\BankTransactionController::class, 'accountBalance']);

        Route::get('payments',                         [\App\Http\Controllers\Banking\BankTransactionController::class, 'paymentsIndex']);
        Route::post('payments',                        [\App\Http\Controllers\Banking\BankTransactionController::class, 'storePayment']);
        Route::post('payments/{id}/void',              [\App\Http\Controllers\Banking\BankTransactionController::class, 'voidPayment']);

        Route::get('deposits',                         [\App\Http\Controllers\Banking\BankTransactionController::class, 'depositsIndex']);
        Route::post('deposits',                        [\App\Http\Controllers\Banking\BankTransactionController::class, 'storeDeposit']);
        Route::post('deposits/{id}/void',              [\App\Http\Controllers\Banking\BankTransactionController::class, 'voidDeposit']);

        Route::get('transfers',                        [\App\Http\Controllers\Banking\BankTransactionController::class, 'transfersIndex']);
        Route::post('transfers',                       [\App\Http\Controllers\Banking\BankTransactionController::class, 'storeTransfer']);
        Route::post('transfers/{id}/void',             [\App\Http\Controllers\Banking\BankTransactionController::class, 'voidTransfer']);

        // ── GL Integrity ──────────────────────────────────────────────────────
        Route::get('gl-integrity', [\App\Http\Controllers\Banking\GlIntegrityController::class, 'check']);

        // ── Bank Transaction Inquiry ──────────────────────────────────────────
        Route::get('bank-inquiry/form-data', [\App\Http\Controllers\Banking\BankTransactionInquiryController::class, 'formData']);
        Route::get('bank-inquiry',           [\App\Http\Controllers\Banking\BankTransactionInquiryController::class, 'index']);

        // ── Petty Cash (legacy requests) ──────────────────────────────────────
        Route::get('petty-cash/form-data',       [\App\Http\Controllers\Banking\PettyCashController::class, 'formData']);
        Route::get('petty-cash',                 [\App\Http\Controllers\Banking\PettyCashController::class, 'index']);
        Route::post('petty-cash',                [\App\Http\Controllers\Banking\PettyCashController::class, 'store']);
        Route::post('petty-cash/{id}/approve',   [\App\Http\Controllers\Banking\PettyCashController::class, 'approve']);
        Route::post('petty-cash/{id}/disburse',  [\App\Http\Controllers\Banking\PettyCashController::class, 'disburse']);
        Route::post('petty-cash/{id}/retire',    [\App\Http\Controllers\Banking\PettyCashController::class, 'retire']);

        // ── Petty Cash Module (imprest fund system) ───────────────────────────
        Route::get('petty-cash/dashboard',       [\App\Http\Controllers\Banking\PettyCashFundController::class, 'dashboard']);
        Route::get('petty-cash/fund-form-data',  [\App\Http\Controllers\Banking\PettyCashFundController::class, 'formData']);
        Route::get('petty-cash/funds',           [\App\Http\Controllers\Banking\PettyCashFundController::class, 'index']);
        Route::post('petty-cash/funds',          [\App\Http\Controllers\Banking\PettyCashFundController::class, 'store']);
        Route::get('petty-cash/funds/{id}',      [\App\Http\Controllers\Banking\PettyCashFundController::class, 'show']);
        Route::put('petty-cash/funds/{id}',      [\App\Http\Controllers\Banking\PettyCashFundController::class, 'update']);

        Route::get('petty-cash/vouchers',                [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'index']);
        Route::post('petty-cash/vouchers',               [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'store']);
        Route::get('petty-cash/vouchers/{id}',           [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'show']);
        Route::post('petty-cash/vouchers/{id}/approve',  [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'approve']);
        Route::post('petty-cash/vouchers/{id}/reject',   [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'reject']);
        Route::post('petty-cash/vouchers/{id}/void',     [\App\Http\Controllers\Banking\PettyCashVoucherController::class, 'void']);

        Route::get('petty-cash/reconciliations',                   [\App\Http\Controllers\Banking\PettyCashReconciliationController::class, 'index']);
        Route::post('petty-cash/reconciliations',                  [\App\Http\Controllers\Banking\PettyCashReconciliationController::class, 'store']);
        Route::get('petty-cash/reconciliations/{id}',              [\App\Http\Controllers\Banking\PettyCashReconciliationController::class, 'show']);
        Route::post('petty-cash/reconciliations/{id}/finalize',    [\App\Http\Controllers\Banking\PettyCashReconciliationController::class, 'finalize']);
        Route::post('petty-cash/reconciliations/{id}/countersign', [\App\Http\Controllers\Banking\PettyCashReconciliationController::class, 'countersign']);

        Route::get('petty-cash/replenishments',               [\App\Http\Controllers\Banking\PettyCashReplenishmentController::class, 'index']);
        Route::post('petty-cash/replenishments',              [\App\Http\Controllers\Banking\PettyCashReplenishmentController::class, 'store']);
        Route::get('petty-cash/replenishments/{id}',          [\App\Http\Controllers\Banking\PettyCashReplenishmentController::class, 'show']);
        Route::post('petty-cash/replenishments/{id}/approve', [\App\Http\Controllers\Banking\PettyCashReplenishmentController::class, 'approve']);
        Route::post('petty-cash/replenishments/{id}/confirm', [\App\Http\Controllers\Banking\PettyCashReplenishmentController::class, 'confirm']);

        // ── Banking & GL Reports ───────────────────────────────────────────────
        Route::prefix('reports')->group(function () {
            Route::get('form-data',       [\App\Http\Controllers\Banking\BankingReportsController::class, 'formData']);
            Route::get('trial-balance',   [\App\Http\Controllers\Banking\BankingReportsController::class, 'trialBalance']);
            Route::get('profit-loss',     [\App\Http\Controllers\Banking\BankingReportsController::class, 'profitLoss']);
            Route::get('balance-sheet',   [\App\Http\Controllers\Banking\BankingReportsController::class, 'balanceSheet']);
            Route::get('gl-listing',      [\App\Http\Controllers\Banking\BankingReportsController::class, 'glListing']);
            Route::get('journal-listing', [\App\Http\Controllers\Banking\BankingReportsController::class, 'journalListing']);
            Route::get('allocation-report', [\App\Http\Controllers\Banking\BankingReportsController::class, 'allocationReport']);
            Route::get('cash-flow',       [\App\Http\Controllers\Banking\BankingReportsController::class, 'cashFlow']);
            Route::get('budget-vs-actuals', [\App\Http\Controllers\Banking\BankingReportsController::class, 'budgetVsActuals']);
            Route::post('budget',         [\App\Http\Controllers\Banking\BankingReportsController::class, 'setBudget']);
        });
    });

    // ── Inventory ──────────────────────────────────────────────────────────────
    Route::prefix('inventory')->group(function () {
        Route::get('kpis',                  [InventoryKpiController::class,         'index']);
        Route::post('check-availability',   [InventoryKpiController::class,         'checkAvailability']);
        Route::get('movements',  [StockMovementInquiryController::class, 'index']);

        // ── Inventory Reports ─────────────────────────────────────────────────
        Route::get('reports/valuation',     [InventoryReportController::class, 'valuation']);
        Route::get('reports/reorder',       [InventoryReportController::class, 'reorder']);
        Route::get('reports/aging',         [InventoryReportController::class, 'aging']);
        Route::get('reports/slow-moving',   [InventoryReportController::class, 'slowMoving']);
        Route::get('reports/warehouse',     [InventoryReportController::class, 'warehouseSummary']);

        // Item Categories
        Route::get('item-categories',          [ItemCategoryController::class, 'index']);
        Route::post('item-categories',         [ItemCategoryController::class, 'store']);
        Route::put('item-categories/{id}',     [ItemCategoryController::class, 'update']);
        Route::delete('item-categories/{id}',  [ItemCategoryController::class, 'destroy']);

        // Items
        Route::get('items/search',              [ItemController::class, 'search']);
        Route::post('items/bulk-no-sale',       [ItemController::class, 'bulkSetNoSale']);
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

        // Pack Conversion — executes the conversion-items ratios above,
        // converting on-hand bulk stock into pack-size SKU quantities.
        Route::get('items/{itemId}/pack-conversion/preview',       [PackConversionController::class, 'preview']);
        Route::post('items/{itemId}/pack-conversion',              [PackConversionController::class, 'store']);

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

        // Adjustment Reasons (spillage, shrinkage, theft, sampling, expiry, consumable, ...)
        Route::get('adjustment-reasons',              [AdjustmentReasonController::class, 'index']);
        Route::post('adjustment-reasons',              [AdjustmentReasonController::class, 'store']);
        Route::put('adjustment-reasons/{id}',          [AdjustmentReasonController::class, 'update']);
        Route::delete('adjustment-reasons/{id}',       [AdjustmentReasonController::class, 'destroy']);

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
        Route::post('consumable-issues/{id}/submit',         [ConsumableIssueController::class, 'submit']);
        Route::post('consumable-issues/{id}/finance-approve',[ConsumableIssueController::class, 'financeApprove']);
        Route::post('consumable-issues/{id}/issue',          [ConsumableIssueController::class, 'issue']);
        Route::post('consumable-issues/{id}/reject',         [ConsumableIssueController::class, 'reject']);
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

        // Weighbridge
        Route::get('weighbridge',                     [\App\Http\Controllers\Inventory\WeighbridgeController::class, 'index']);
        Route::post('weighbridge',                    [\App\Http\Controllers\Inventory\WeighbridgeController::class, 'store']);
        Route::get('weighbridge/stats',               [\App\Http\Controllers\Inventory\WeighbridgeController::class, 'stats']);
        Route::get('weighbridge/read-scale',          [\App\Http\Controllers\Inventory\WeighbridgeController::class, 'readScale']);
        Route::post('weighbridge/{id}/apply',         [\App\Http\Controllers\Inventory\WeighbridgeController::class, 'apply']);
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

        Route::get('debit-note-reasons',          [\App\Http\Controllers\Sales\DebitNoteReasonController::class, 'index']);
        Route::post('debit-note-reasons',         [\App\Http\Controllers\Sales\DebitNoteReasonController::class, 'store']);
        Route::put('debit-note-reasons/{id}',     [\App\Http\Controllers\Sales\DebitNoteReasonController::class, 'update']);
        Route::delete('debit-note-reasons/{id}',  [\App\Http\Controllers\Sales\DebitNoteReasonController::class, 'destroy']);

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
        Route::get('suppliers',                         [SupplierController::class, 'index']);
        Route::post('suppliers',                        [SupplierController::class, 'store']);
        Route::get('suppliers/{id}',                    [SupplierController::class, 'show']);
        Route::put('suppliers/{id}',                    [SupplierController::class, 'update']);
        Route::delete('suppliers/{id}',                 [SupplierController::class, 'destroy']);
        Route::post('suppliers/import/preview',                  [SupplierImportController::class, 'preview']);
        Route::post('suppliers/import/confirm',                  [SupplierImportController::class, 'confirm']);
        Route::post('suppliers/import-purchases/preview',        [SupplierPurchaseImportController::class, 'preview']);
        Route::post('suppliers/import-purchases/confirm',        [SupplierPurchaseImportController::class, 'confirm']);

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
        Route::get('orders/grn-items-for-invoice',              [PurchaseOrderController::class, 'grnItemsForInvoice']);
        Route::get('orders',                                    [PurchaseOrderController::class, 'index']);
        Route::post('orders',                                   [PurchaseOrderController::class, 'store']);
        Route::get('orders/{id}',                               [PurchaseOrderController::class, 'show']);
        Route::put('orders/{id}',                               [PurchaseOrderController::class, 'update']);
        Route::delete('orders/{id}',                            [PurchaseOrderController::class, 'destroy']);
        Route::post('orders/{id}/submit',                       [PurchaseOrderController::class, 'submit']);
        Route::post('orders/{id}/hod-approve',                  [PurchaseOrderController::class, 'hodApprove']);
        Route::post('orders/{id}/finance-approve',              [PurchaseOrderController::class, 'financeApprove']);
        Route::post('orders/{id}/ceo-approve',                  [PurchaseOrderController::class, 'ceoApprove']);
        Route::post('orders/{id}/receive',                      [PurchaseOrderController::class, 'receiveGoods']);
        Route::post('orders/{id}/reject',                       [PurchaseOrderController::class, 'reject']);
        Route::get('orders/{id}/gl-transactions',               [PurchaseOrderController::class, 'glTransactions']);

        // Supplier Credit Notes
        Route::get('supplier-credit-notes/form-data',           [SupplierCreditNoteController::class, 'formData']);
        Route::get('supplier-credit-notes/received-items',      [SupplierCreditNoteController::class, 'receivedItems']);
        Route::get('supplier-credit-notes',                     [SupplierCreditNoteController::class, 'index']);
        Route::post('supplier-credit-notes',                    [SupplierCreditNoteController::class, 'store']);
        Route::get('supplier-credit-notes/{id}/gl',            [SupplierCreditNoteController::class, 'glEntries']);
        Route::get('supplier-credit-notes/{id}',                [SupplierCreditNoteController::class, 'show']);

        // Supplier Debit Notes
        Route::get('supplier-debit-notes/form-data',            [SupplierDebitNoteController::class, 'formData']);
        Route::get('supplier-debit-notes',                      [SupplierDebitNoteController::class, 'index']);
        Route::post('supplier-debit-notes',                     [SupplierDebitNoteController::class, 'store']);
        Route::get('supplier-debit-notes/{id}/gl',              [SupplierDebitNoteController::class, 'glEntries']);
        Route::get('supplier-debit-notes/{id}',                 [SupplierDebitNoteController::class, 'show']);

        // Supplier Allocations
        Route::get('supplier-allocations',                      [SupplierAllocationController::class, 'index']);
        Route::get('supplier-allocation-inquiry',               [SupplierAllocationController::class, 'inquiry']);
        Route::get('supplier-transaction-inquiry',              [SupplierAllocationController::class, 'transactionInquiry']);
        Route::get('supplier-transaction-allocations',          [SupplierAllocationController::class, 'transactionAllocations']);
        Route::get('available-payments',                        [SupplierAllocationController::class, 'availablePayments']);
        Route::get('available-journals',                        [SupplierAllocationController::class, 'availableJournals']);
        Route::get('invoice-allocations',                       [SupplierAllocationController::class, 'invoiceAllocations']);
        Route::post('supplier-payment-allocations',             [SupplierAllocationController::class, 'allocatePayment']);
        Route::post('supplier-journal-allocations',             [SupplierAllocationController::class, 'allocateJournal']);
        Route::get('available-credit-notes',                    [SupplierAllocationController::class, 'availableCreditNotes']);
        Route::post('supplier-credit-note-allocations',         [SupplierAllocationController::class, 'allocateCreditNote']);
        Route::get('credit-note-allocated-invoices',            [SupplierAllocationController::class, 'creditNoteAllocatedInvoices']);
        Route::get('invoice-full-detail',                       [SupplierAllocationController::class, 'invoiceFullDetail']);

        // Payment Vouchers
        Route::get('payment-vouchers/form-data',          [PaymentVoucherController::class, 'formData']);
        Route::get('payment-vouchers/open-transactions',  [PaymentVoucherController::class, 'openTransactions']);
        Route::get('payment-vouchers/approved',           [PaymentVoucherController::class, 'approvedVouchers']);
        Route::get('payment-vouchers',                    [PaymentVoucherController::class, 'index']);
        Route::post('payment-vouchers',                   [PaymentVoucherController::class, 'store']);
        Route::post('payment-vouchers/direct-pay',        [PaymentVoucherController::class, 'directPay']);
        Route::get('payment-vouchers/{id}',               [PaymentVoucherController::class, 'show']);
        Route::get('payment-vouchers/{id}/gl-transactions',  [PaymentVoucherController::class, 'glTransactions']);
        Route::post('payment-vouchers/{id}/payables-approve', [PaymentVoucherController::class, 'payablesApprove']);
        Route::post('payment-vouchers/{id}/finance-approve',  [PaymentVoucherController::class, 'financeApprove']);
        Route::post('payment-vouchers/{id}/ceo-approve',      [PaymentVoucherController::class, 'ceoApprove']);
        Route::post('payment-vouchers/{id}/post',             [PaymentVoucherController::class, 'post']);
        Route::post('payment-vouchers/{id}/correct-wht-gl',   [PaymentVoucherController::class, 'correctWithholdingTaxGl']);

        // ── Purchases Reports ────────────────────────────────────────────────
        Route::prefix('reports')->group(function () {
            Route::get('form-data',              [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'formData']);
            Route::get('purchase-summary',       [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'purchaseSummary']);
            Route::get('purchase-orders',        [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'purchaseOrdersReport']);
            Route::get('grn-report',             [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'grnReport']);
            Route::get('supplier-aged-analysis', [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'supplierAgedAnalysis']);
            Route::get('supplier-statement',     [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'supplierStatement']);
            Route::get('outstanding-pos',        [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'outstandingPOs']);
            Route::get('top-suppliers',          [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'topSuppliers']);
            Route::get('voucher-payment',        [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'voucherPaymentReport']);
            Route::get('supplier-credit-notes',  [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'supplierCreditNotes']);
            Route::get('export',                 [\App\Http\Controllers\Purchases\PurchaseReportsController::class, 'export']);
        });
    });

    // ── Sales Transactions ─────────────────────────────────────────────────

    // Customers — form-data MUST be before {id} to avoid route conflict
    Route::get('sales/customers/form-data',     [CustomerController::class, 'formData']);
    Route::get('sales/customers/map-locations', [CustomerController::class, 'mapLocations']);
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

    // Payment Channels (M-Pesa paybills now; basis for bank accounts etc. later)
    Route::get('sales/payment-channels',                 [PaymentChannelController::class, 'index']);

    // Sales Invoices (Direct)
    Route::get('sales/invoices/next-ref',               [SalesInvoiceController::class, 'nextRef']);
    Route::get('sales/invoices',                         [SalesInvoiceController::class, 'index']);
    Route::post('sales/invoices',                        [SalesInvoiceController::class, 'store']);
    Route::get('sales/invoices/{id}',                    [SalesInvoiceController::class, 'show']);
    Route::get('sales/creditnote/{id}',            [SalesInvoiceController::class, 'showCredit']);
    Route::put('sales/invoices/{id}',                    [SalesInvoiceController::class, 'update']);
    Route::post('sales/invoices/{id}/place',             [SalesInvoiceController::class, 'place']);
    Route::post('sales/invoices/{id}/stamp',             [SalesInvoiceController::class, 'stamp']);
    Route::post('sales/invoices/{id}/cancel',            [SalesInvoiceController::class, 'cancel']);
    Route::post('sales/invoices/{id}/items',             [SalesInvoiceController::class, 'addItem']);
    Route::put('sales/invoices/{id}/items/{itemId}',     [SalesInvoiceController::class, 'updateItem']);
    Route::delete('sales/invoices/{id}/items/{itemId}',  [SalesInvoiceController::class, 'removeItem']);
    Route::get('sales/invoices/{id}/gl-entries',         [SalesInvoiceController::class, 'glEntries']);
    Route::get('sales/invoices/{id}/allocations',        [SalesInvoiceController::class, 'allocations']);
    Route::post('sales/invoices/{id}/apply-payments',    [SalesInvoiceController::class, 'applyPayments']);
    Route::get('sales/invoices/{id}/returnable-items',   [SalesInvoiceController::class, 'returnableItems']);

    // Reports
    Route::get('sales/reports/quantity-comparison', \App\Http\Controllers\Sales\SalesQuantityComparisonController::class);
    Route::get('sales/reports/product-sales/form-data', [\App\Http\Controllers\Sales\ProductSalesReportController::class, 'formData']);
    Route::get('sales/reports/product-sales',           [\App\Http\Controllers\Sales\ProductSalesReportController::class, 'index']);
    Route::get('sales/reports/product-sales/export',    [\App\Http\Controllers\Sales\ProductSalesReportController::class, 'exportExcel']);

    // Sales module reports
    Route::prefix('sales/reports')->group(function () {
        Route::get('form-data',              [\App\Http\Controllers\Sales\SalesReportsController::class, 'formData']);
        Route::get('sales-summary',          [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesSummary']);
        Route::get('sales-by-customer',      [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesByCustomer']);
        Route::get('sales-by-item',          [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesByItem']);
        Route::get('sales-by-salesperson',   [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesBySalesperson']);
        Route::get('sales-by-area',          [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesByArea']);
        Route::get('customer-aged-analysis', [\App\Http\Controllers\Sales\SalesReportsController::class, 'customerAgedAnalysis']);
        Route::get('customer-statement',     [\App\Http\Controllers\Sales\SalesReportsController::class, 'customerStatement']);
        Route::get('outstanding-invoices',   [\App\Http\Controllers\Sales\SalesReportsController::class, 'outstandingInvoices']);
        Route::get('price-list',             [\App\Http\Controllers\Sales\SalesReportsController::class, 'priceList']);
        Route::get('delivery-status',        [\App\Http\Controllers\Sales\SalesReportsController::class, 'deliveryStatus']);
        Route::get('credit-notes-report',    [\App\Http\Controllers\Sales\SalesReportsController::class, 'creditNotes']);
        Route::get('top-customers',          [\App\Http\Controllers\Sales\SalesReportsController::class, 'topCustomers']);
        Route::get('sales-variance',         [\App\Http\Controllers\Sales\SalesReportsController::class, 'salesVariance']);
        Route::get('recurring-invoice',      [\App\Http\Controllers\Sales\SalesReportsController::class, 'recurringInvoiceSummary']);
        Route::get('export',                 [\App\Http\Controllers\Sales\SalesReportsController::class, 'export']);
    });

    // Credit Notes
    Route::get('sales/credit-notes',                           [CreditNoteController::class, 'index']);
    Route::post('sales/credit-notes',                          [CreditNoteController::class, 'store']);
    Route::get('sales/credit-notes/unallocated',               [CreditNoteController::class, 'unallocated']);
    Route::get('sales/credit-notes/{id}',                      [CreditNoteController::class, 'show']);
    Route::post('sales/credit-notes/{id}/place',               [CreditNoteController::class, 'place']);
    Route::post('sales/credit-notes/{id}/stamp',               [CreditNoteController::class, 'stamp']);
    Route::post('sales/credit-notes/{id}/cancel',              [CreditNoteController::class, 'cancel']);
    Route::post('sales/credit-notes/{id}/allocate-manual',     [CreditNoteController::class, 'allocateManual']);

    // Debit Notes
    Route::get('sales/debit-notes/next-ref',   [\App\Http\Controllers\Sales\DebitNoteController::class, 'nextRef']);
    Route::get('sales/debit-notes/form-data',  [\App\Http\Controllers\Sales\DebitNoteController::class, 'formData']);
    Route::get('sales/debit-notes',            [\App\Http\Controllers\Sales\DebitNoteController::class, 'index']);
    Route::post('sales/debit-notes',           [\App\Http\Controllers\Sales\DebitNoteController::class, 'store']);
    Route::get('sales/debit-notes/{id}',       [\App\Http\Controllers\Sales\DebitNoteController::class, 'show']);
    Route::put('sales/debit-notes/{id}',       [\App\Http\Controllers\Sales\DebitNoteController::class, 'update']);
    Route::post('sales/debit-notes/{id}/place',  [\App\Http\Controllers\Sales\DebitNoteController::class, 'place']);
    Route::post('sales/debit-notes/{id}/cancel', [\App\Http\Controllers\Sales\DebitNoteController::class, 'cancel']);

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
    Route::get('sales/dashboard/grader-detail',            [SalesDashboardController::class, 'graderDetail']);
    Route::get('sales/dashboard/widgets',                  [SalesDashboardController::class, 'widgetData']);

    // ── Milk Analytics / Forecast ────────────────────────────────────────────
    Route::get('analytics/milk/forecast',  [MilkForecastController::class, 'forecast']);
    Route::get('analytics/milk/insights',  [MilkForecastController::class, 'insights']);
    Route::post('analytics/milk/advice',   [MilkForecastController::class, 'advice']);

    // ── Sales Analytics ──────────────────────────────────────────────────────
    Route::get('analytics/sales/forecast',         [SalesAnalyticsController::class, 'forecast']);
    Route::get('analytics/sales/insights',         [SalesAnalyticsController::class, 'insights']);
    Route::post('analytics/sales/advice',          [SalesAnalyticsController::class, 'advice']);

    // ── Inventory Analytics ──────────────────────────────────────────────────
    Route::get('analytics/inventory/forecast',     [InventoryAnalyticsController::class, 'forecast']);
    Route::get('analytics/inventory/insights',     [InventoryAnalyticsController::class, 'insights']);
    Route::post('analytics/inventory/advice',      [InventoryAnalyticsController::class, 'advice']);

    // ── Manufacturing Analytics ──────────────────────────────────────────────
    Route::get('analytics/manufacturing/forecast', [ManufacturingAnalyticsController::class, 'forecast']);
    Route::get('analytics/manufacturing/insights', [ManufacturingAnalyticsController::class, 'insights']);
    Route::post('analytics/manufacturing/advice',  [ManufacturingAnalyticsController::class, 'advice']);

    // ── Purchases Analytics ──────────────────────────────────────────────────
    Route::get('analytics/purchases/forecast',     [PurchasesAnalyticsController::class, 'forecast']);
    Route::get('analytics/purchases/insights',     [PurchasesAnalyticsController::class, 'insights']);
    Route::post('analytics/purchases/advice',      [PurchasesAnalyticsController::class, 'advice']);

    // ── Financial Analytics ──────────────────────────────────────────────────
    Route::get('analytics/financial/summary',      [FinancialAnalyticsController::class, 'summary']);

    // ── AI Chat ──────────────────────────────────────────────────────────────
    Route::post('analytics/chat',                  [AiChatController::class, 'chat']);

    // ── ESP (External Agrovets & Service Providers) ──────────────────────────
    Route::get   ('esp/dashboard',                       [EspController::class, 'dashboard'])->middleware('permission:view-esp');
    Route::get   ('esp/providers/next-code',             [EspController::class, 'nextCode'])->middleware('permission:view-esp');
    Route::get   ('esp/providers',                       [EspController::class, 'indexProviders'])->middleware('permission:view-esp');
    Route::post  ('esp/providers',                       [EspController::class, 'storeProvider'])->middleware('permission:manage-esp');
    Route::get   ('esp/providers/{provider}',            [EspController::class, 'showProvider'])->middleware('permission:view-esp');
    Route::put   ('esp/providers/{provider}',             [EspController::class, 'updateProvider'])->middleware('permission:manage-esp');
    Route::get   ('esp/farmers/{farmerId}/credit',       [EspController::class, 'farmerCredit'])->middleware('permission:view-esp');
    Route::get   ('esp/farmer-sales',                    [EspController::class, 'indexFarmerSales'])->middleware('permission:view-esp');
    Route::post  ('esp/farmer-sales',                    [EspController::class, 'storeFarmerSale'])->middleware('permission:manage-esp');
    Route::get   ('esp/farmer-sales/{sale}',             [EspController::class, 'showFarmerSale'])->middleware('permission:view-esp');
    Route::get   ('esp/company-purchases',               [EspController::class, 'indexCompanyPurchases'])->middleware('permission:view-esp');
    Route::post  ('esp/company-purchases',               [EspController::class, 'storeCompanyPurchase'])->middleware('permission:manage-esp');
    Route::get   ('esp/company-purchases/{purchase}',    [EspController::class, 'showCompanyPurchase'])->middleware('permission:view-esp');
    Route::get   ('esp/settlements',                     [EspController::class, 'indexSettlements'])->middleware('permission:view-esp');
    Route::post  ('esp/settlements/preview',             [EspController::class, 'previewSettlement'])->middleware('permission:manage-esp');
    Route::post  ('esp/settlements',                     [EspController::class, 'postSettlement'])->middleware('permission:manage-esp');

    // ── ESP: multi-party sales (mobile app — farmers, employees, transporters) ──
    Route::get   ('esp/credit-score',                    [EspController::class, 'creditScore'])->middleware('permission:view-esp');
    Route::get   ('esp/parties',                         [EspController::class, 'indexParties'])->middleware('permission:view-esp');
    Route::get   ('esp/sales',                           [EspController::class, 'indexSales'])->middleware('permission:view-esp');
    Route::post  ('esp/sales',                           [EspController::class, 'storeSale'])->middleware('permission:manage-esp');
    Route::get   ('esp/sales/{sale}',                    [EspController::class, 'showSale'])->middleware('permission:view-esp');
    Route::put   ('esp/sales/{sale}',                    [EspController::class, 'updateSale'])->middleware('permission:manage-esp');
    Route::post  ('esp/sales/{sale}/void',               [EspController::class, 'voidSale'])->middleware('permission:manage-esp');
    Route::post  ('esp/sales/{sale}/adjust',             [EspController::class, 'adjustSale'])->middleware('permission:manage-esp');

    // ── SACCO (Member Savings, Shares & Loans) ────────────────────────────────
    Route::get   ('sacco/dashboard',                     [SaccoController::class, 'dashboard'])->middleware('permission:view-sacco');
    Route::get   ('sacco/members',                       [SaccoController::class, 'indexMembers'])->middleware('permission:view-sacco');
    Route::post  ('sacco/members',                       [SaccoController::class, 'storeMember'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/members/{member}',               [SaccoController::class, 'showMember'])->middleware('permission:view-sacco');
    Route::put   ('sacco/members/{member}',               [SaccoController::class, 'updateMember'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/accounts',                      [SaccoController::class, 'indexAccounts'])->middleware('permission:view-sacco');
    Route::post  ('sacco/accounts/{account}/deposit',    [SaccoController::class, 'deposit'])->middleware('permission:manage-sacco');
    Route::post  ('sacco/accounts/{account}/withdraw',   [SaccoController::class, 'withdraw'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/loan-products',                 [SaccoController::class, 'indexLoanProducts'])->middleware('permission:view-sacco');
    Route::post  ('sacco/loan-products',                 [SaccoController::class, 'storeLoanProduct'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/loans',                         [SaccoController::class, 'indexLoans'])->middleware('permission:view-sacco');
    Route::post  ('sacco/loans',                         [SaccoController::class, 'storeLoanApplication'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/loans/{loan}',                  [SaccoController::class, 'showLoan'])->middleware('permission:view-sacco');
    Route::post  ('sacco/loans/{loan}/approve',          [SaccoController::class, 'approveLoan'])->middleware('permission:approve-sacco-loans');
    Route::post  ('sacco/loans/{loan}/disburse',         [SaccoController::class, 'disburseLoan'])->middleware('permission:approve-sacco-loans');
    Route::post  ('sacco/loans/{loan}/reject',           [SaccoController::class, 'rejectLoan'])->middleware('permission:approve-sacco-loans');
    Route::post  ('sacco/loans/{loan}/repay-cash',       [SaccoController::class, 'repayCash'])->middleware('permission:manage-sacco');
    Route::post  ('sacco/checkoff/post',                 [SaccoController::class, 'postCheckoffForPeriod'])->middleware('permission:manage-sacco');
    Route::get   ('sacco/repayments',                    [SaccoController::class, 'repaymentHistory'])->middleware('permission:view-sacco');

    // ── Audit ────────────────────────────────────────────────────────────────
    Route::post('audit/run', [AuditController::class, 'run']);

    // ── eTIMS Setup ──────────────────────────────────────────────────────────
    Route::get ('etims/status',            [EtimsSetupController::class, 'status']);
    Route::post('etims/initialize',        [EtimsSetupController::class, 'initialize']);
    Route::post('etims/sync-codes',        [EtimsSetupController::class, 'syncCodes']);
    Route::post('etims/sync-item-classes', [EtimsSetupController::class, 'syncItemClasses']);
    Route::post('etims/sync-branches',     [EtimsSetupController::class, 'syncBranches']);
    Route::post('etims/sync-notices',      [EtimsSetupController::class, 'syncNotices']);

    // ── eTIMS Settings ───────────────────────────────────────────────────────
    Route::get ('etims/settings',                 [EtimsSettingsController::class, 'show']);
    Route::put ('etims/settings',                 [EtimsSettingsController::class, 'update']);
    Route::post('etims/settings/test-connection', [EtimsSettingsController::class, 'testConnection']);

    // ── eTIMS Maintenance ────────────────────────────────────────────────────
    Route::get ('etims/items',                        [EtimsController::class, 'items']);
    Route::put ('etims/items/{id}',                   [EtimsController::class, 'updateItem']);
    Route::post('etims/items/{id}/register',          [EtimsController::class, 'registerItem']);
    Route::post('etims/items/register-all',           [EtimsController::class, 'registerAllItems']);
    Route::get ('etims/items/kra',                    [EtimsController::class, 'kraItems']);
    Route::get ('etims/purchases',                    [EtimsController::class, 'purchases']);
    Route::post('etims/purchases/{id}/register',      [EtimsController::class, 'registerPurchase']);
    Route::get ('etims/purchases/kra',                [EtimsController::class, 'kraPurchases']);
    Route::get ('etims/invoices',                     [EtimsController::class, 'invoices']);
    Route::get ('etims/stock/kra',                    [EtimsController::class, 'kraStock']);
    Route::post('etims/stock/movement',               [EtimsController::class, 'saveStockMovement']);
    Route::get ('etims/customers/lookup',             [EtimsController::class, 'lookupCustomer']);
    Route::get('sales/mpesa/tills',                       [MpesaController::class, 'tills']);
    Route::get('sales/mpesa/status',                      [MpesaController::class, 'checkStatus']);
    Route::get('sales/mpesa',                             [MpesaController::class, 'index']);
    Route::post('sales/mpesa',                            [MpesaController::class, 'store']);
    Route::post('sales/mpesa/{id}/transfer',              [MpesaController::class, 'transfer']);

    // ── Payments (dynamic: STK push + bank transfer, see config/payment.php) ──
    Route::post('sales/payments/initiate',                            [PaymentController::class, 'initiate']);
    Route::get('sales/payments/{reference}/status',                   [PaymentController::class, 'status']);
    Route::post('sales/payments/bank-transfer/{reference}/confirm',   [PaymentController::class, 'confirmBankTransfer']);

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
        Route::get('collection-chart',          [FarmerKpiController::class,    'collectionChart']);

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
        Route::get('routes/{id}/farmers',       [MilkRouteController::class, 'farmers']);
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

        Route::get('quality-settings',          [MilkQualitySettingController::class, 'show']);
        Route::put('quality-settings',          [MilkQualitySettingController::class, 'update']);

        // Farmers (suppliers)
        Route::get('farmers/search',             [FarmerController::class, 'search']);
        Route::get('farmers/form-data',         [FarmerController::class, 'formData']);
        Route::get('farmers/map-locations',     [FarmerController::class, 'mapLocations']);
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
        Route::get('milk-purchases/summary',              [MilkPurchaseController::class, 'summary']);
        Route::get('milk-purchases/rejections',           [MilkPurchaseController::class, 'rejections']);
        Route::post('milk-purchases/reserve-reference',   [MilkPurchaseController::class, 'reserveReference']);
        Route::post('milk-purchases/bulk-approve',        [MilkPurchaseController::class, 'bulkApprove']);
        Route::post('milk-purchases/bulk-reject',         [MilkPurchaseController::class, 'bulkReject']);
        Route::delete('milk-purchases/draft/{id}',        [MilkPurchaseController::class, 'discardDraft']);
        Route::get('milk-purchases',                      [MilkPurchaseController::class, 'index']);
        Route::post('milk-purchases',                     [MilkPurchaseController::class, 'store']);
        Route::get('milk-purchases/{id}',                 [MilkPurchaseController::class, 'show']);
        Route::get('milk-purchases/{id}/gl-entries',      [MilkPurchaseController::class, 'glEntries']);
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

        // Farmer Payment Schedule Report
        Route::get('farmer-payment-schedule/form-data', [\App\Http\Controllers\Farmers\FarmerPaymentScheduleController::class, 'formData']);
        Route::get('farmer-payment-schedule',           [\App\Http\Controllers\Farmers\FarmerPaymentScheduleController::class, 'schedule']);

        // Milk Location Transfers
        Route::get('milk-location-transfers/form-data',          [MilkLocationTransferController::class, 'formData']);
        Route::get('milk-location-transfers/available-quantity', [MilkLocationTransferController::class, 'availableQuantity']);
        Route::get('milk-location-transfers',                    [MilkLocationTransferController::class, 'index']);
        Route::post('milk-location-transfers',                   [MilkLocationTransferController::class, 'store']);

        // Milk Transfer Receptions (cooling-store reconciliation of a
        // dispatched trip — weighing, quality retest, accept/reject, and
        // transporter charge-back)
        Route::get('milk-transfer-receptions/pending', [\App\Http\Controllers\Farmers\MilkTransferReceptionController::class, 'pendingTrips']);
        Route::get('milk-transfer-receptions',         [\App\Http\Controllers\Farmers\MilkTransferReceptionController::class, 'index']);
        Route::post('milk-transfer-receptions',        [\App\Http\Controllers\Farmers\MilkTransferReceptionController::class, 'store']);
        Route::get('milk-transfer-receptions/{id}',    [\App\Http\Controllers\Farmers\MilkTransferReceptionController::class, 'show']);

        // Farmer Supplier Payments
        Route::get('supplier-payments/form-data',       [FarmerSupplierPaymentController::class, 'formData']);
        Route::get('supplier-payments/advance-limit',   [FarmerSupplierPaymentController::class, 'advanceLimit']);
        Route::get('supplier-payments/account-balance', [FarmerSupplierPaymentController::class, 'accountBalance']);
        Route::get('supplier-payments',                 [FarmerSupplierPaymentController::class, 'index']);
        Route::post('supplier-payments',                [FarmerSupplierPaymentController::class, 'store']);

        // Supplier List Report
        Route::get('supplier-list',                [SupplierListController::class, 'index']);

        // Farmer Advance Report
        Route::get('advance-report/form-data',     [FarmerAdvanceReportController::class, 'formData']);
        Route::get('advance-report',               [FarmerAdvanceReportController::class, 'index']);

        // Grader Payroll
        Route::get('grader-payroll/form-data',   [GraderPayrollController::class, 'formData']);
        Route::post('grader-payroll/process',    [GraderPayrollController::class, 'process']);
        Route::post('grader-payroll/settle',     [GraderPayrollController::class, 'settle']);
        Route::post('grader-payroll/close',      [GraderPayrollController::class, 'close']);
        Route::post('grader-payroll/advances',   [GraderPayrollController::class, 'storeAdvance']);
        Route::post('grader-payroll/rates',      [GraderPayrollController::class, 'saveRate']);

        // Spillage — optional grader accountability charge (write-off itself
        // is posted via the generic Inventory Adjustments engine)
        Route::post('spillage/{adjustmentId}/charge-grader', [FarmersSpillageController::class, 'chargeGrader']);

        // Import Services
        Route::get('import-services/form-data',    [ImportServicesController::class, 'formData']);
        Route::post('import-services/import',      [ImportServicesController::class, 'import']);

        // Service Posting (manual deduction entry)
        Route::get('service-postings/form-data',   [\App\Http\Controllers\Farmers\ServicePostingController::class, 'formData']);
        Route::get('service-postings/report',      [\App\Http\Controllers\Farmers\ServicePostingController::class, 'report']);
        Route::get('service-postings',             [\App\Http\Controllers\Farmers\ServicePostingController::class, 'index']);
        Route::post('service-postings',            [\App\Http\Controllers\Farmers\ServicePostingController::class, 'store']);
        Route::delete('service-postings/{id}',     [\App\Http\Controllers\Farmers\ServicePostingController::class, 'destroy']);

        // Milk Supplier History Report
        Route::get('milk-supplier-report/form-data', [\App\Http\Controllers\Farmers\MilkSupplierReportController::class, 'formData']);
        Route::get('milk-supplier-report',           [\App\Http\Controllers\Farmers\MilkSupplierReportController::class, 'report']);

        // Milk Supplier Statement
        Route::get('milk-supplier-statement/form-data', [\App\Http\Controllers\Farmers\MilkSupplierStatementController::class, 'formData']);
        Route::get('milk-supplier-statement',           [\App\Http\Controllers\Farmers\MilkSupplierStatementController::class, 'statement']);

        // Milk Supplier Payslips
        Route::get('milk-payslips/form-data', [\App\Http\Controllers\Farmers\MilkPayslipController::class, 'formData']);
        Route::get('milk-payslips',           [\App\Http\Controllers\Farmers\MilkPayslipController::class, 'payslips']);

        // SACCO Bank Schedule — recovered loan repayments & other deductions
        Route::get('sacco-bank-schedule/form-data', [\App\Http\Controllers\Farmers\SaccoBankScheduleController::class, 'formData']);
        Route::get('sacco-bank-schedule',           [\App\Http\Controllers\Farmers\SaccoBankScheduleController::class, 'schedule']);

        // Route / Grader Milk Transfers
        Route::get('route-grader-transfers/form-data', [\App\Http\Controllers\Farmers\RouteGraderTransferController::class, 'formData']);
        Route::get('route-grader-transfers/search',    [\App\Http\Controllers\Farmers\RouteGraderTransferController::class, 'search']);
        Route::post('route-grader-transfers/process',  [\App\Http\Controllers\Farmers\RouteGraderTransferController::class, 'process']);

        // Milk Purchase Reversals
        Route::get('milk-purchase-reversals/form-data', [\App\Http\Controllers\Farmers\MilkPurchaseReversalController::class, 'formData']);
        Route::get('milk-purchase-reversals/search',    [\App\Http\Controllers\Farmers\MilkPurchaseReversalController::class, 'search']);
        Route::post('milk-purchase-reversals/{id}',     [\App\Http\Controllers\Farmers\MilkPurchaseReversalController::class, 'reverse']);
        Route::post('milk-purchase-reversals/bulk',     [\App\Http\Controllers\Farmers\MilkPurchaseReversalController::class, 'bulkReverse']);

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
        Route::get('types',          [\App\Http\Controllers\Manufacturing\ManufacturingTypeController::class, 'index']);
        Route::post('types',         [\App\Http\Controllers\Manufacturing\ManufacturingTypeController::class, 'store']);
        Route::get('types/{id}',     [\App\Http\Controllers\Manufacturing\ManufacturingTypeController::class, 'show']);
        Route::put('types/{id}',     [\App\Http\Controllers\Manufacturing\ManufacturingTypeController::class, 'update']);
        Route::delete('types/{id}',  [\App\Http\Controllers\Manufacturing\ManufacturingTypeController::class, 'destroy']);
        Route::get('kpis',                               [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'kpis']);
        Route::get('work-centres',                       [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'index']);
        Route::post('work-centres',                      [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'store']);
        Route::put('work-centres/{id}',                  [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'update']);
        Route::delete('work-centres/{id}',               [\App\Http\Controllers\Manufacturing\WorkCentreController::class, 'destroy']);
        Route::get('boms/items-search',                  [\App\Http\Controllers\Manufacturing\BomController::class,        'itemsSearch']);
        Route::post('boms/import',                       [\App\Http\Controllers\Manufacturing\BomController::class,        'import']);
        Route::get('boms',                               [\App\Http\Controllers\Manufacturing\BomController::class,        'index']);
        Route::post('boms',                              [\App\Http\Controllers\Manufacturing\BomController::class,        'store']);
        Route::get('boms/{id}',                          [\App\Http\Controllers\Manufacturing\BomController::class,        'show']);
        Route::put('boms/{id}',                          [\App\Http\Controllers\Manufacturing\BomController::class,        'update']);
        Route::delete('boms/{id}',                       [\App\Http\Controllers\Manufacturing\BomController::class,        'destroy']);
        Route::post('boms/{id}/clone',                   [\App\Http\Controllers\Manufacturing\BomController::class,        'clone']);
        Route::get('work-orders',                        [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'index']);
        Route::post('work-orders',                       [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'store']);
        Route::get('work-orders/{id}',                   [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'show']);
        Route::put('work-orders/{id}',                   [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'update']);
        Route::post('work-orders/{id}/release',          [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'release']);
        Route::post('work-orders/{id}/issue-all',        [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'issueAll']);
        Route::post('work-orders/{id}/complete',         [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'complete']);
        Route::post('work-orders/{id}/settle',           [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'settle']);
        Route::post('work-orders/{id}/labour',           [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'addLabour']);
        Route::post('work-orders/{id}/overhead',              [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'addOverhead']);
        Route::get('work-orders/{id}/cost-sheet',             [\App\Http\Controllers\Manufacturing\WorkOrderController::class,  'costSheet']);
        Route::get('work-orders/{id}/stages',                 [\App\Http\Controllers\Manufacturing\WorkOrderStageController::class, 'index']);
        Route::post('work-orders/{id}/stages/{stageCode}/complete', [\App\Http\Controllers\Manufacturing\WorkOrderStageController::class, 'complete']);
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
        // ── Manufacturing Reports ─────────────────────────────────────────────
        Route::prefix('reports')->group(function () {
            Route::get('form-data',             [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'formData']);
            Route::get('production-orders',     [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'productionOrders']);
            Route::get('efficiency',            [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'efficiency']);
            Route::get('qc-failure',            [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'qcFailure']);
            Route::get('bom-utilisation',       [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'bomUtilisation']);
            Route::get('output-summary',        [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'outputSummary']);
            Route::get('machine-downtime',      [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'machineDowntime']);
            Route::get('cost-of-production',    [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'costOfProduction']);
            Route::get('wo-variance',           [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'woVariance']);
            Route::get('production-plan-status',[\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'productionPlanStatus']);
            Route::get('labour-utilisation',    [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'labourUtilisation']);
            Route::get('export',                [\App\Http\Controllers\Manufacturing\ManufacturingReportController::class, 'export']);
        });
    });

    // ── Casual Workers ────────────────────────────────────────────────────────
    Route::prefix('casual-workers')->group(function () {
        Route::get('kpis',                                         [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class,         'kpis']);
        Route::get('form-data',                                    [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class,         'formData']);
        Route::get('next-ref',                                     [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class,         'nextRef']);
        Route::get('/',                                            [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class,         'index']);
        Route::post('/',                                           [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class,         'store']);

        // Sub-resource prefix groups must come BEFORE the {id} wildcard so that
        // GET /casual-workers/attendance etc. are not swallowed by show().
        Route::prefix('trades')->group(function () {
            Route::get('/',        [\App\Http\Controllers\CasualWorkers\CasualWorkerTradeController::class,   'index']);
            Route::post('/',       [\App\Http\Controllers\CasualWorkers\CasualWorkerTradeController::class,   'store']);
            Route::put('{id}',     [\App\Http\Controllers\CasualWorkers\CasualWorkerTradeController::class,   'update']);
            Route::delete('{id}',  [\App\Http\Controllers\CasualWorkers\CasualWorkerTradeController::class,   'destroy']);
        });

        Route::prefix('attendance')->group(function () {
            Route::get('/',         [\App\Http\Controllers\CasualWorkers\CasualWorkerAttendanceController::class, 'index']);
            Route::post('bulk',     [\App\Http\Controllers\CasualWorkers\CasualWorkerAttendanceController::class, 'bulkSave']);
            Route::put('{id}',      [\App\Http\Controllers\CasualWorkers\CasualWorkerAttendanceController::class, 'update']);
            Route::get('summary',   [\App\Http\Controllers\CasualWorkers\CasualWorkerAttendanceController::class, 'summary']);
            Route::get('dates',     [\App\Http\Controllers\CasualWorkers\CasualWorkerAttendanceController::class, 'dates']);
        });

        Route::prefix('pay-rates')->group(function () {
            Route::get('/',        [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRateController::class, 'index']);
            Route::post('/',       [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRateController::class, 'store']);
            Route::put('{id}',     [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRateController::class, 'update']);
            Route::delete('{id}',  [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRateController::class, 'destroy']);
        });

        Route::prefix('pay-runs')->group(function () {
            Route::get('/',                      [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'index']);
            Route::post('/',                     [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'store']);
            Route::get('report',                 [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'payRunReport']);
            Route::get('{id}',                   [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'show']);
            Route::post('{id}/generate',         [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'generate']);
            Route::post('{id}/generate-chunked', [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'generateChunked']);
            Route::post('{id}/approve',          [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'approve']);
            Route::post('{id}/post-earnings',        [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'postEarnings']);
            Route::post('{id}/post-worker-earning', [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'postWorkerEarning']);
            Route::get('{id}/mpesa-export',      [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'mpesaExport']);
            Route::put('{runId}/items/{itemId}', [\App\Http\Controllers\CasualWorkers\CasualWorkerPayRunController::class, 'updateItem']);
        });

        Route::prefix('earning-types')->group(function () {
            Route::get('/',       [\App\Http\Controllers\CasualWorkers\CasualEarningTypeController::class, 'index']);
            Route::post('/',      [\App\Http\Controllers\CasualWorkers\CasualEarningTypeController::class, 'store']);
            Route::put('{id}',    [\App\Http\Controllers\CasualWorkers\CasualEarningTypeController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\CasualWorkers\CasualEarningTypeController::class, 'destroy']);
        });

        // Wildcard routes last — must not shadow the prefix groups above.
        Route::get('{id}',    [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class, 'show']);
        Route::put('{id}',    [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class, 'update']);
        Route::delete('{id}', [\App\Http\Controllers\CasualWorkers\CasualWorkerController::class, 'destroy']);
    });

    // ── Blockchain ────────────────────────────────────────────────────────────
    Route::prefix('blockchain')->group(function () {
        Route::get('anchor',    [\App\Http\Controllers\Blockchain\BlockchainController::class, 'getAnchor']);
        Route::post('verify',   [\App\Http\Controllers\Blockchain\BlockchainController::class, 'verify']);
        Route::get('history',   [\App\Http\Controllers\Blockchain\BlockchainController::class, 'history']);
    });

    // ── Location tracking (proxy to Go tracking service on :8090) ────────────
    Route::post('tracking/ingest', function (\Illuminate\Http\Request $req) {
        $validated = $req->validate([
            'identifier'  => 'required|string|max:100',
            'device_type' => 'required|string|max:30',
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            'altitude'    => 'nullable|numeric',
            'speed'       => 'nullable|numeric|min:0',
            'accuracy'    => 'nullable|numeric|min:0',
            'fix_time'    => 'nullable|string',
        ]);
        $validated['user_id']  = auth()->user()?->user_id ?? $validated['identifier'];
        $validated['fix_time'] = $validated['fix_time'] ?? now()->toIso8601String();

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(5)
                ->post('http://localhost:8090/tracking/api/positions/ingest', $validated);
            return response()->json($resp->json(), $resp->status());
        } catch (\Exception) {
            // Tracking service unavailable — acknowledge so app does not error
            return response()->json(['success' => true, 'message' => 'Position received'], 202);
        }
    });

    // ── Tracking: current user's own movements ────────────────────────────────
    Route::get('tracking/me', function (\Illuminate\Http\Request $req) {
        $identifier = auth()->user()?->user_id;
        $date = $req->get('date', now()->format('Y-m-d'));
        try {
            $go = 'http://localhost:8090/tracking/api';
            $devicesResp = \Illuminate\Support\Facades\Http::timeout(5)->get("{$go}/devices");
            $device = collect($devicesResp->json()['data'] ?? [])
                ->firstWhere('identifier', $identifier);
            if (!$device) {
                return response()->json(['success' => true, 'data' => ['device' => null, 'distance' => null, 'positions' => []]]);
            }
            $id = $device['id'];
            [$distResp, $posResp] = [
                \Illuminate\Support\Facades\Http::timeout(5)->get("{$go}/positions/distance", ['device_id' => $id, 'date' => $date]),
                \Illuminate\Support\Facades\Http::timeout(5)->get("{$go}/positions", ['device_id' => $id, 'from' => "{$date}T00:00:00Z", 'to' => "{$date}T23:59:59Z", 'order' => 'asc', 'limit' => 2000]),
            ];
            return response()->json(['success' => true, 'data' => [
                'device'    => $device,
                'distance'  => $distResp->json()['data'] ?? null,
                'positions' => $posResp->json()['data'] ?? [],
            ]]);
        } catch (\Exception) {
            return response()->json(['success' => true, 'data' => ['device' => null, 'distance' => null, 'positions' => []]]);
        }
    });

    Route::get('tracking/me/history', function () {
        $identifier = auth()->user()?->user_id;
        try {
            $go = 'http://localhost:8090/tracking/api';
            $devicesResp = \Illuminate\Support\Facades\Http::timeout(5)->get("{$go}/devices");
            $device = collect($devicesResp->json()['data'] ?? [])
                ->firstWhere('identifier', $identifier);
            if (!$device) {
                return response()->json(['success' => true, 'data' => ['device' => null, 'history' => []]]);
            }
            $datesResp = \Illuminate\Support\Facades\Http::timeout(5)
                ->get("{$go}/positions/dates", ['device_id' => $device['id']]);
            return response()->json(['success' => true, 'data' => [
                'device'  => $device,
                'history' => $datesResp->json()['data'] ?? [],
            ]]);
        } catch (\Exception) {
            return response()->json(['success' => true, 'data' => ['device' => null, 'history' => []]]);
        }
    });
});

// ── Transport ───────────────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('transport')->group(function () {
    Route::get('kpis',            [TransportDashboardController::class, 'kpis'])->middleware('permission:view-transport');
    Route::get('fleet-registry',  [TransportDashboardController::class, 'fleetRegistry'])->middleware('permission:view-transport');

    // Routes (transport_routes)
    Route::get('routes',          [TransportRouteController::class, 'index'])->middleware('permission:view-transport');
    Route::post('routes',         [TransportRouteController::class, 'store'])->middleware('permission:manage-transport');
    Route::put('routes/{id}',     [TransportRouteController::class, 'update'])->middleware('permission:manage-transport');
    Route::delete('routes/{id}',  [TransportRouteController::class, 'destroy'])->middleware('permission:manage-transport');

    // Loading orders
    Route::get('loading-orders/form-data',     [LoadingOrderController::class, 'formData'])->middleware('permission:view-transport');
    Route::get('loading-orders',               [LoadingOrderController::class, 'index'])->middleware('permission:view-transport');
    Route::get('loading-orders/{id}',          [LoadingOrderController::class, 'show'])->middleware('permission:view-transport');
    Route::post('loading-orders',              [LoadingOrderController::class, 'store'])->middleware('permission:manage-transport');
    Route::put('loading-orders/{id}',          [LoadingOrderController::class, 'update'])->middleware('permission:manage-transport');
    Route::post('loading-orders/{id}/dispatch',         [LoadingOrderController::class, 'dispatch'])->middleware('permission:manage-transport');
    Route::post('loading-orders/{id}/confirm-delivery', [LoadingOrderController::class, 'confirmDelivery'])->middleware('permission:manage-transport');
    Route::delete('loading-orders/{id}',       [LoadingOrderController::class, 'destroy'])->middleware('permission:manage-transport');

    // Fuel entries
    Route::get('fuel-entries/form-data', [FuelEntryController::class, 'formData'])->middleware('permission:view-transport');
    Route::get('fuel-entries',           [FuelEntryController::class, 'index'])->middleware('permission:view-transport');
    Route::post('fuel-entries',          [FuelEntryController::class, 'store'])->middleware('permission:manage-transport');
    Route::put('fuel-entries/{id}',      [FuelEntryController::class, 'update'])->middleware('permission:manage-transport');
    Route::delete('fuel-entries/{id}',   [FuelEntryController::class, 'destroy'])->middleware('permission:manage-transport');

    // Maintenance records
    Route::get('maintenance-records/form-data', [MaintenanceRecordController::class, 'formData'])->middleware('permission:view-transport');
    Route::get('maintenance-records',           [MaintenanceRecordController::class, 'index'])->middleware('permission:view-transport');
    Route::post('maintenance-records',          [MaintenanceRecordController::class, 'store'])->middleware('permission:manage-transport');
    Route::put('maintenance-records/{id}',      [MaintenanceRecordController::class, 'update'])->middleware('permission:manage-transport');
    Route::delete('maintenance-records/{id}',   [MaintenanceRecordController::class, 'destroy'])->middleware('permission:manage-transport');
});


// ── HRM ────────────────────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('hrm')->group(function () {
    // KPIs
    Route::get('kpis', [\App\Http\Controllers\Hrm\HrmKpiController::class, 'index'])->middleware('permission:view-hrm');

    // Employees
    Route::get('employees/form-data', [\App\Http\Controllers\Hrm\EmployeeController::class, 'formData'])->middleware('permission:view-hrm');
    Route::get('employees',           [\App\Http\Controllers\Hrm\EmployeeController::class, 'index'])->middleware('permission:view-hrm');
    Route::get('employees/{id}',      [\App\Http\Controllers\Hrm\EmployeeController::class, 'show'])->middleware('permission:view-hrm');
    Route::post('employees',          [\App\Http\Controllers\Hrm\EmployeeController::class, 'store'])->middleware('permission:manage-hrm');
    Route::put('employees/{id}',      [\App\Http\Controllers\Hrm\EmployeeController::class, 'update'])->middleware('permission:manage-hrm');
    Route::delete('employees/{id}',   [\App\Http\Controllers\Hrm\EmployeeController::class, 'destroy'])->middleware('permission:manage-hrm');
    Route::post('employees/{id}/convert-to-customer', [\App\Http\Controllers\Hrm\EmployeeController::class, 'convertToCustomer'])->middleware('permission:manage-hrm');

    // Departments
    Route::get('departments',         [\App\Http\Controllers\Hrm\DepartmentController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('departments',        [\App\Http\Controllers\Hrm\DepartmentController::class, 'store'])->middleware('permission:manage-hrm');
    Route::put('departments/{id}',    [\App\Http\Controllers\Hrm\DepartmentController::class, 'update'])->middleware('permission:manage-hrm');
    Route::delete('departments/{id}', [\App\Http\Controllers\Hrm\DepartmentController::class, 'destroy'])->middleware('permission:manage-hrm');

    // Job titles
    Route::get('job-titles',          [\App\Http\Controllers\Hrm\JobTitleController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('job-titles',         [\App\Http\Controllers\Hrm\JobTitleController::class, 'store'])->middleware('permission:manage-hrm');
    Route::put('job-titles/{id}',     [\App\Http\Controllers\Hrm\JobTitleController::class, 'update'])->middleware('permission:manage-hrm');
    Route::delete('job-titles/{id}',  [\App\Http\Controllers\Hrm\JobTitleController::class, 'destroy'])->middleware('permission:manage-hrm');

    // Leave types
    Route::get('leave-types',         [\App\Http\Controllers\Hrm\LeaveTypeController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('leave-types',        [\App\Http\Controllers\Hrm\LeaveTypeController::class, 'store'])->middleware('permission:manage-hrm');
    Route::put('leave-types/{id}',    [\App\Http\Controllers\Hrm\LeaveTypeController::class, 'update'])->middleware('permission:manage-hrm');
    Route::delete('leave-types/{id}', [\App\Http\Controllers\Hrm\LeaveTypeController::class, 'destroy'])->middleware('permission:manage-hrm');

    // Leave requests
    Route::get('leave-requests',                [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('leave-requests',               [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'store'])->middleware('permission:view-hrm');
    Route::post('leave-requests/{id}/approve',  [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'approve'])->middleware('permission:manage-hrm');
    Route::post('leave-requests/{id}/reject',   [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'reject'])->middleware('permission:manage-hrm');
    Route::post('leave-requests/{id}/cancel',   [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'cancel'])->middleware('permission:view-hrm');
    Route::get('employees/{id}/leave-balance',  [\App\Http\Controllers\Hrm\LeaveRequestController::class, 'balance'])->middleware('permission:view-hrm')->whereNumber('id');

    // Attendance
    Route::post('attendance/check-in',        [\App\Http\Controllers\Hrm\AttendanceController::class, 'checkIn'])->middleware('permission:view-hrm');
    Route::post('attendance/check-out',       [\App\Http\Controllers\Hrm\AttendanceController::class, 'checkOut'])->middleware('permission:view-hrm');
    Route::get('attendance/me',               [\App\Http\Controllers\Hrm\AttendanceController::class, 'me'])->middleware('permission:view-hrm');
    Route::get('attendance',                  [\App\Http\Controllers\Hrm\AttendanceController::class, 'forDate'])->middleware('permission:view-hrm');
    Route::post('attendance/manual',          [\App\Http\Controllers\Hrm\AttendanceController::class, 'manual'])->middleware('permission:manage-hrm');
    Route::get('employees/{id}/attendance',   [\App\Http\Controllers\Hrm\AttendanceController::class, 'history'])->middleware('permission:view-hrm')->whereNumber('id');

    // Onboarding / Offboarding checklists (shared engine, distinguished by ?type=)
    Route::get('checklist-items',                [\App\Http\Controllers\Hrm\ChecklistItemController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('checklist-items',               [\App\Http\Controllers\Hrm\ChecklistItemController::class, 'store'])->middleware('permission:manage-hrm');
    Route::put('checklist-items/{id}',           [\App\Http\Controllers\Hrm\ChecklistItemController::class, 'update'])->middleware('permission:manage-hrm');
    Route::delete('checklist-items/{id}',        [\App\Http\Controllers\Hrm\ChecklistItemController::class, 'destroy'])->middleware('permission:manage-hrm');

    Route::get('checklist-processes',            [\App\Http\Controllers\Hrm\ChecklistProcessController::class, 'index'])->middleware('permission:view-hrm');
    Route::post('employees/{id}/checklist/start', [\App\Http\Controllers\Hrm\ChecklistProcessController::class, 'start'])->middleware('permission:manage-hrm')->whereNumber('id');
    Route::get('employees/{id}/checklist',        [\App\Http\Controllers\Hrm\ChecklistProcessController::class, 'show'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('checklist-tasks/{id}/toggle',    [\App\Http\Controllers\Hrm\ChecklistProcessController::class, 'toggleTask'])->middleware('permission:view-hrm');
    Route::post('checklist-processes/{id}/complete', [\App\Http\Controllers\Hrm\ChecklistProcessController::class, 'complete'])->middleware('permission:manage-hrm');

    // Performance — goals
    Route::get('employees/{id}/goals',    [\App\Http\Controllers\Hrm\PerformanceController::class, 'goals'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('employees/{id}/goals',   [\App\Http\Controllers\Hrm\PerformanceController::class, 'storeGoal'])->middleware('permission:manage-hrm')->whereNumber('id');
    Route::put('goals/{id}',              [\App\Http\Controllers\Hrm\PerformanceController::class, 'updateGoal'])->middleware('permission:manage-hrm');
    Route::delete('goals/{id}',           [\App\Http\Controllers\Hrm\PerformanceController::class, 'destroyGoal'])->middleware('permission:manage-hrm');

    // Performance — appraisal reviews
    Route::get('employees/{id}/reviews',       [\App\Http\Controllers\Hrm\PerformanceController::class, 'reviews'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('employees/{id}/reviews',      [\App\Http\Controllers\Hrm\PerformanceController::class, 'storeReview'])->middleware('permission:manage-hrm')->whereNumber('id');
    Route::put('reviews/{id}',                 [\App\Http\Controllers\Hrm\PerformanceController::class, 'updateReview'])->middleware('permission:manage-hrm');
    Route::post('reviews/{id}/submit',         [\App\Http\Controllers\Hrm\PerformanceController::class, 'submitReview'])->middleware('permission:manage-hrm');
    Route::post('reviews/{id}/acknowledge',    [\App\Http\Controllers\Hrm\PerformanceController::class, 'acknowledgeReview'])->middleware('permission:view-hrm');

    // Compensation
    Route::get('employees/{id}/compensation',        [\App\Http\Controllers\Hrm\CompensationController::class, 'summary'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('employees/{id}/salary-revisions',   [\App\Http\Controllers\Hrm\CompensationController::class, 'reviseSalary'])->middleware('permission:manage-hrm')->whereNumber('id');

    // Employee documents
    Route::get('employees/{id}/documents',    [\App\Http\Controllers\Hrm\EmployeeDocumentController::class, 'index'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('employees/{id}/documents',   [\App\Http\Controllers\Hrm\EmployeeDocumentController::class, 'store'])->middleware('permission:manage-hrm')->whereNumber('id');
    Route::delete('documents/{id}',           [\App\Http\Controllers\Hrm\EmployeeDocumentController::class, 'destroy'])->middleware('permission:manage-hrm');

    // HR Letters
    Route::get('letter-templates',            [\App\Http\Controllers\Hrm\HrLetterController::class, 'templates'])->middleware('permission:view-hrm');
    Route::post('letter-templates',           [\App\Http\Controllers\Hrm\HrLetterController::class, 'storeTemplate'])->middleware('permission:manage-hrm');
    Route::put('letter-templates/{id}',       [\App\Http\Controllers\Hrm\HrLetterController::class, 'updateTemplate'])->middleware('permission:manage-hrm');
    Route::delete('letter-templates/{id}',    [\App\Http\Controllers\Hrm\HrLetterController::class, 'destroyTemplate'])->middleware('permission:manage-hrm');
    Route::get('employees/{id}/letters',      [\App\Http\Controllers\Hrm\HrLetterController::class, 'forEmployee'])->middleware('permission:view-hrm')->whereNumber('id');
    Route::post('employees/{id}/letters/{templateId}/generate', [\App\Http\Controllers\Hrm\HrLetterController::class, 'generate'])->middleware('permission:manage-hrm')->whereNumber('id')->whereNumber('templateId');
});


// ── Payroll ──────────────────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('payroll')->group(function () {
    Route::get('periods',                       [PayrollController::class, 'index'])->middleware('permission:view-payroll');
    Route::post('periods',                      [PayrollController::class, 'store'])->middleware('permission:manage-payroll');
    Route::get('periods/{id}',                  [PayrollController::class, 'show'])->middleware('permission:view-payroll');
    Route::post('periods/{id}/generate',        [PayrollController::class, 'generate'])->middleware('permission:manage-payroll');
    Route::post('periods/{id}/approve',         [PayrollController::class, 'approve'])->middleware('permission:manage-payroll');
    Route::post('periods/{id}/post-to-gl',      [PayrollController::class, 'postToGl'])->middleware('permission:manage-payroll');
    Route::put('periods/{periodId}/items/{itemId}', [PayrollController::class, 'updateItem'])->middleware('permission:manage-payroll');
    Route::get('periods/{id}/bank-file',        [PayrollController::class, 'bankFile'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/paye',             [PayrollController::class, 'payeComputation'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/nhif',             [PayrollController::class, 'nhifReturns'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/nssf',             [PayrollController::class, 'nssfReturns'])->middleware('permission:view-payroll');

    Route::get('p9-report',                     [PayrollController::class, 'p9Report'])->middleware('permission:view-payroll');
    Route::get('summary-report',                [PayrollController::class, 'summaryReport'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/payslip-report',   [PayrollController::class, 'payslipReport'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/staff-deductions', [PayrollController::class, 'staffDeductionStatement'])->middleware('permission:view-payroll');
    Route::get('periods/{id}/bank-schedule',    [PayrollController::class, 'bankSchedule'])->middleware('permission:view-payroll');

    Route::get('components',                    [PayrollController::class, 'componentsIndex'])->middleware('permission:view-payroll');
    Route::post('components',                   [PayrollController::class, 'componentsStore'])->middleware('permission:manage-payroll');
    Route::put('components/{id}',               [PayrollController::class, 'componentsUpdate'])->middleware('permission:manage-payroll');
    Route::delete('components/{id}',            [PayrollController::class, 'componentsDestroy'])->middleware('permission:manage-payroll');

    Route::get('employee-components',           [PayrollController::class, 'employeeComponentsIndex'])->middleware('permission:view-payroll');
    Route::post('employee-components',          [PayrollController::class, 'employeeComponentsStore'])->middleware('permission:manage-payroll');
    Route::put('employee-components/{id}',      [PayrollController::class, 'employeeComponentsUpdate'])->middleware('permission:manage-payroll');
    Route::delete('employee-components/{id}',   [PayrollController::class, 'employeeComponentsDestroy'])->middleware('permission:manage-payroll');
});


// ── Internal service callbacks (HMAC-authenticated, no Bearer token) ──────────
Route::post('internal/bulk-approval-result', BulkApprovalCallbackController::class);

// ── SigNoz alert webhook (bearer token via config('alerts.webhook_token')) ────
Route::post('alerts/nexora-alerts', AlertWebhookController::class);

// ── Payment provider webhooks (no Bearer token — Safaricom can't send one) ────
// Keep this URL out of anything a customer-facing client calls; restrict at the
// network/firewall level to Safaricom's published IP ranges if possible.
Route::post('payments/mpesa/stk/callback', [PaymentController::class, 'mpesaStkCallback']);
