<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    /**
     * GET /api/setup/company
     * Returns the single company preferences record (creates default if none exists).
     */
    public function show(): JsonResponse
    {
        $prefs = CompanyPreference::first() ?? new CompanyPreference();

        $data = $prefs->toArray();

        // Append a public logo URL if a logo is stored
        $data['logo_url'] = $prefs->logo_filename
            ? Storage::url('logos/' . $prefs->logo_filename)
            : null;

        return ApiResponse::success($data, 'Company settings retrieved');
    }

    /**
     * PUT /api/setup/company
     * Updates (or creates) company preferences.
     * Accepts multipart/form-data so a logo file can be uploaded.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                    => 'sometimes|string|max:255',
            'address'                 => 'sometimes|nullable|string',
            'domicile'                => 'sometimes|nullable|string|max:255',
            'phone'                   => 'sometimes|nullable|string|max:50',
            'fax'                     => 'sometimes|nullable|string|max:50',
            'email'                   => 'sometimes|nullable|email|max:255',
            'bcc_email'               => 'sometimes|nullable|email|max:255',
            'company_number'          => 'sometimes|nullable|string|max:100',
            'kra_pin'                 => 'sometimes|nullable|string|max:50',
            'currency'                => 'sometimes|string|max:100',
            'logo'                    => 'sometimes|nullable|file|image|max:2048',
            'delete_logo'             => 'sometimes|boolean',
            'auto_revaluation'        => 'sometimes|boolean',
            'timezone_on_reports'     => 'sometimes|boolean',
            'logo_on_reports'         => 'sometimes|boolean',
            'barcodes_on_stocks'      => 'sometimes|boolean',
            'auto_increase_refs'      => 'sometimes|boolean',
            'fiscal_year'             => 'sometimes|nullable|string|max:100',
            'fiscal_status'           => 'sometimes|string|in:Active,Closed',
            'tax_periods'             => 'sometimes|integer|min:1',
            'tax_last_period'         => 'sometimes|integer|min:1',
            'alt_tax_on_docs'             => 'sometimes|boolean',
            'suppress_tax_rates'          => 'sometimes|boolean',
            'allow_negative_inventory'       => 'sometimes|boolean',
            'allow_farmer_invoicing'         => 'sometimes|boolean',
            'farmer_invoice_limit_percent'   => 'sometimes|numeric|min:0|max:100',
            'po_requires_hod_approval'     => 'sometimes|boolean',
            'po_requires_finance_approval' => 'sometimes|boolean',
            'po_requires_ceo_approval'     => 'sometimes|boolean',
            'pr_requires_hod_approval'     => 'sometimes|boolean',
            'pr_requires_finance_approval' => 'sometimes|boolean',
            'pr_requires_ceo_approval'     => 'sometimes|boolean',
            'base_price_calc'         => 'sometimes|string|max:100',
            'add_price_from_std_cost' => 'sometimes|nullable|numeric',
            'round_prices'            => 'sometimes|integer|min:0',
            'module_manufacturing'    => 'sometimes|boolean',
            'module_fixed_assets'     => 'sometimes|boolean',
            'use_dimensions'          => 'sometimes|integer|in:0,1,2',
            'short_name_in_list'      => 'sometimes|boolean',
            'search_item_list'        => 'sometimes|boolean',
            'search_customer_list'    => 'sometimes|boolean',
            'search_supplier_list'    => 'sometimes|boolean',
            'login_timeout'           => 'sometimes|integer|min:0',
        ]);

        $prefs = CompanyPreference::first() ?? new CompanyPreference();

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Remove old logo if exists
            if ($prefs->logo_filename) {
                Storage::delete('public/logos/' . $prefs->logo_filename);
            }
            $filename = $request->file('logo')->store('logos', 'public');
            $validated['logo_filename'] = basename($filename);
        }

        // Handle logo deletion
        if (!empty($validated['delete_logo'])) {
            if ($prefs->logo_filename) {
                Storage::delete('public/logos/' . $prefs->logo_filename);
            }
            $validated['logo_filename'] = null;
        }

        // Remove non-model keys
        unset($validated['logo'], $validated['delete_logo']);

        $prefs->fill($validated)->save();

        $data = $prefs->fresh()->toArray();
        $data['logo_url'] = $prefs->logo_filename
            ? Storage::url('logos/' . $prefs->logo_filename)
            : null;

        return ApiResponse::success($data, 'Company settings updated successfully');
    }
}
