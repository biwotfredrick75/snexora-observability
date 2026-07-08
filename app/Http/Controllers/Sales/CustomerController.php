<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\CreditStatus;
use App\Models\Dimension;
use App\Models\GlAccount;
use App\Models\InventoryLocation;
use App\Models\PaymentTerm;
use App\Models\SalesArea;
use App\Models\SalesGroup;
use App\Models\SalesPerson;
use App\Models\SalesType;
use App\Models\ShippingCompany;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\TaxGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q        = trim($request->get('q', ''));
        $inactive = $request->boolean('inactive', false);

        $query = Customer::with(['branches', 'priceList', 'paymentTerm', 'creditStatus'])
            ->withSum(['invoices as invoiced_total' => fn ($q) => $q->where('status', 'placed')], 'amount_total')
            ->withSum('allocations as allocated_total', 'amount');

        if (! $inactive) {
            $query->where('inactive', false);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('debtor_no', 'like', "%{$q}%");
            });
        }

        $customers = $query->orderBy('name')->get();

        // current_credit is a stored column that's never updated by invoicing/payments —
        // compute the real outstanding exposure instead: placed invoices minus allocations.
        $customers->each(function ($c) {
            $c->current_credit = round(max(0, (float) ($c->invoiced_total ?? 0) - (float) ($c->allocated_total ?? 0)), 2);
        });

        return ApiResponse::success($customers, 'Customers retrieved');
    }

    public function show(string $id): JsonResponse
    {
        $customer = Customer::with(['branches', 'priceList', 'paymentTerm', 'creditStatus'])
            ->where('debtor_no', $id)
            ->first();

        if (! $customer) {
            return ApiResponse::notFound('Customer not found');
        }

        $outstandingInvoicesCount = DB::table('sales_invoices')
            ->where('debtor_no', $id)
            ->where('status', 'placed')
            ->count();

        $invoicedTotal  = (float) DB::table('sales_invoices')->where('debtor_no', $id)->where('status', 'placed')->sum('amount_total');
        $allocatedTotal = (float) DB::table('debtor_allocations')->where('debtor_no', $id)->sum('amount');

        $data = $customer->toArray();
        $data['outstanding_invoices_count'] = $outstandingInvoicesCount;
        $data['current_credit'] = round(max(0, $invoicedTotal - $allocatedTotal), 2);

        return ApiResponse::success($data, 'Customer retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'debtor_no'                => 'nullable|string|max:20|unique:customers,debtor_no',
            'farmer_no'                => 'nullable|string|max:20|unique:customers,farmer_no',
            'name'                     => 'required|string|max:100',
            'short_name'               => 'nullable|string|max:30',
            'address'                  => 'nullable|string',
            'phone'                    => 'nullable|string|max:30',
            'email'                    => 'nullable|string|max:100',
            'kra_pin'                  => 'nullable|string|max:20',
            'currency'                 => 'nullable|string|max:10',
            'payment_terms'            => 'nullable|integer',
            'price_list_id'            => 'nullable|integer',
            'discount'                 => 'nullable|numeric|min:0|max:100',
            'credit_limit'             => 'nullable|numeric|min:0',
            'credit_status_id'         => 'nullable|integer',
            'credit_invoices_allowed'  => 'nullable|integer|min:0',
            'prompt_payment_discount'  => 'nullable|numeric|min:0|max:100',
            'general_notes'            => 'nullable|string',
            'dimension_id'             => 'nullable|integer',
            'dimension2_id'            => 'nullable|integer',
            'inactive'                 => 'nullable|boolean',
        ]);

        $num = Customer::nextCustomerNumber();
        $data['customer_number'] = $num;
        if (empty($data['debtor_no'])) {
            $data['debtor_no'] = 'CUST-' . str_pad($num, 4, '0', STR_PAD_LEFT);
        }

        $customer = Customer::create($data);

        return ApiResponse::created($customer, 'Customer created');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Customer::where('debtor_no', $id)->first();

        if (! $customer) {
            return ApiResponse::notFound('Customer not found');
        }

        $data = $request->validate([
            'name'                     => 'sometimes|required|string|max:100',
            'short_name'               => 'nullable|string|max:30',
            'address'                  => 'nullable|string',
            'phone'                    => 'nullable|string|max:30',
            'email'                    => 'nullable|string|max:100',
            'kra_pin'                  => 'nullable|string|max:20',
            'currency'                 => 'nullable|string|max:10',
            'payment_terms'            => 'nullable|integer',
            'price_list_id'            => 'nullable|integer',
            'discount'                 => 'nullable|numeric|min:0|max:100',
            'credit_limit'             => 'nullable|numeric|min:0',
            'credit_status_id'         => 'nullable|integer',
            'credit_invoices_allowed'  => 'nullable|integer|min:0',
            'prompt_payment_discount'  => 'nullable|numeric|min:0|max:100',
            'general_notes'            => 'nullable|string',
            'dimension_id'             => 'nullable|integer',
            'dimension2_id'            => 'nullable|integer',
            'inactive'                 => 'nullable|boolean',
        ]);

        $customer->update($data);

        return ApiResponse::updated($customer->fresh(), 'Customer updated');
    }

    public function destroy(string $id): JsonResponse
    {
        $customer = Customer::where('debtor_no', $id)->first();

        if (! $customer) {
            return ApiResponse::notFound('Customer not found');
        }

        $customer->delete();

        return ApiResponse::deleted('Customer deleted');
    }

    public function branches(string $id): JsonResponse
    {
        $branches = CustomerBranch::where('debtor_no', $id)
            ->orderBy('branch_name')
            ->get();

        return ApiResponse::success($branches, 'Branches retrieved');
    }

    public function transactions(Request $request, string $id): JsonResponse
    {
        $customer = Customer::where('debtor_no', $id)->first();
        if (! $customer) {
            return ApiResponse::notFound('Customer not found');
        }

        $type    = $request->get('type', '');
        $dateFrom = $request->get('date_from', '');
        $dateTo   = $request->get('date_to', '');
        $ref      = $request->get('ref', '');

        // Build invoice (SI) rows
        $invQuery = DB::table('sales_invoices as si')
            ->leftJoin('customer_branches as cb', 'si.branch_id', '=', 'cb.id')
            ->where('si.debtor_no', $id)
            ->where('si.status', 'placed')
            ->select(
                DB::raw("'SI' as type"),
                'si.id',
                'si.inv_no as doc_no',
                DB::raw("'' as order_no"),
                'si.customer_ref as reference',
                'si.invoice_date as doc_date',
                'si.due_date',
                'cb.branch_name',
                'si.amount_total as amount',
                DB::raw("0 as stamp_vat")
            );

        if ($dateFrom) $invQuery->where('si.invoice_date', '>=', $dateFrom);
        if ($dateTo)   $invQuery->where('si.invoice_date', '<=', $dateTo);
        if ($ref)      $invQuery->where('si.customer_ref', 'like', "%{$ref}%");

        $rows = ($type === '' || $type === 'SI') ? $invQuery->get() : collect();

        // Aging buckets (from placed invoices)
        $today = now()->toDateString();
        $aging = [
            'currency'     => $customer->currency ?? 'KES',
            'terms'        => null,
            'current'      => 0,
            'days_1_30'    => 0,
            'days_31_60'   => 0,
            'over_60'      => 0,
            'total'        => 0,
        ];

        $allInvoices = DB::table('sales_invoices')
            ->where('debtor_no', $id)
            ->where('status', 'placed')
            ->get(['amount_total', 'due_date']);

        foreach ($allInvoices as $inv) {
            $amount  = (float) $inv->amount_total;
            $dueDate = $inv->due_date;
            $aging['total'] += $amount;

            if (! $dueDate || $dueDate >= $today) {
                $aging['current'] += $amount;
            } else {
                $diff = (int) now()->diffInDays($dueDate);
                if ($diff <= 30)       $aging['days_1_30']  += $amount;
                elseif ($diff <= 60)   $aging['days_31_60'] += $amount;
                else                   $aging['over_60']    += $amount;
            }
        }

        // Payment term label
        if ($customer->payment_terms) {
            $term = DB::table('payment_terms')->find($customer->payment_terms);
            $aging['terms'] = $term?->description;
        }

        return ApiResponse::success([
            'aging'        => $aging,
            'transactions' => $rows->sortBy('doc_date')->values(),
        ], 'Transactions retrieved');
    }

    public function customerOrders(Request $request, string $id): JsonResponse
    {
        $customer = Customer::where('debtor_no', $id)->first();
        if (! $customer) {
            return ApiResponse::notFound('Customer not found');
        }

        $query = DB::table('sales_orders as so')
            ->leftJoin('customer_branches as cb', 'so.branch_id', '=', 'cb.id')
            ->where('so.debtor_no', $id)
            ->select(
                'so.id',
                'so.so_no',
                'so.customer_ref',
                'so.order_date',
                'so.delivery_date',
                'so.status',
                'so.amount_total',
                'so.location_id',
                'cb.branch_name'
            );

        if ($request->filled('so_no'))    $query->where('so.so_no', 'like', '%'.$request->get('so_no').'%');
        if ($request->filled('ref'))       $query->where('so.customer_ref', 'like', '%'.$request->get('ref').'%');
        if ($request->filled('date_from')) $query->where('so.order_date', '>=', $request->get('date_from'));
        if ($request->filled('date_to'))   $query->where('so.order_date', '<=', $request->get('date_to'));
        if ($request->filled('location_id')) $query->where('so.location_id', $request->get('location_id'));
        if ($request->filled('stock_id')) {
            $query->whereExists(function ($sub) use ($request) {
                $sub->from('sales_order_items')
                    ->whereColumn('sales_order_items.so_id', 'so.id')
                    ->where('sales_order_items.stock_id', $request->get('stock_id'));
            });
        }

        $orders = $query->orderByDesc('so.order_date')->get();

        return ApiResponse::success($orders, 'Customer orders retrieved');
    }

    // ── All-customer transaction inquiry ─────────────────────────────────────
    public function allTransactions(Request $request): JsonResponse
    {
        $customer = $request->get('debtor_no', '');
        $from     = $request->get('date_from', '');
        $to       = $request->get('date_to', '');

        $q = DB::table('sales_invoices as si')
            ->leftJoin('customers as c',         'si.debtor_no',  '=', 'c.debtor_no')
            ->leftJoin('customer_branches as cb', 'si.branch_id',  '=', 'cb.id')
            ->where('si.status', 'placed')
            ->select(
                DB::raw("'SI' as type"),
                'si.id',
                'si.inv_no as doc_no',
                'si.customer_ref as reference',
                'si.invoice_date as doc_date',
                'si.due_date',
                'si.debtor_no',
                'c.name as customer_name',
                'cb.branch_name',
                DB::raw("COALESCE(c.currency, 'KES') as currency"),
                'si.amount_total as amount'
            );

        if ($customer) $q->where('si.debtor_no', $customer);
        if ($from)     $q->where('si.invoice_date', '>=', $from);
        if ($to)       $q->where('si.invoice_date', '<=', $to);

        $rows = $q->orderByDesc('si.invoice_date')
                  ->paginate(min((int) $request->get('per_page', 30), 200));

        return ApiResponse::paginated($rows, 'Transactions retrieved');
    }

    // ── Customer allocation inquiry ───────────────────────────────────────────
    public function allocations(Request $request): JsonResponse
    {
        $customer = $request->get('debtor_no', '');
        $from     = $request->get('date_from', '');
        $to       = $request->get('date_to', '');

        $q = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'si.debtor_no', '=', 'c.debtor_no')
            ->where('si.status', 'placed')
            ->select(
                DB::raw("'SI' as type"),
                'si.id',
                'si.inv_no as doc_no',
                'si.customer_ref as reference',
                'si.invoice_date as doc_date',
                'si.due_date',
                'si.debtor_no',
                'c.name as customer_name',
                DB::raw("COALESCE(c.currency, 'KES') as currency"),
                'si.amount_total as debit',
                DB::raw('0 as credit'),
                DB::raw('0 as allocated'),
                'si.amount_total as balance'
            );

        if ($customer) $q->where('si.debtor_no', $customer);
        if ($from)     $q->where('si.invoice_date', '>=', $from);
        if ($to)       $q->where('si.invoice_date', '<=', $to);

        $rows = $q->orderByDesc('si.invoice_date')
                  ->paginate(min((int) $request->get('per_page', 30), 200));

        return ApiResponse::paginated($rows, 'Allocations retrieved');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'payment_terms'      => PaymentTerm::where('inactive', false)->get(['id', 'description']),
            'sales_types'        => SalesType::all(['id', 'type_name']),
            'credit_statuses'    => CreditStatus::all(['id', 'description']),
            'dimensions'         => Dimension::where('inactive', false)->get(['id', 'reference', 'name']),
            'sales_persons'      => SalesPerson::all(['id', 'name']),
            'sales_areas'        => SalesArea::all(['id', 'area_name']),
            'sales_groups'       => SalesGroup::all(['id', 'group_name']),
            'locations'          => InventoryLocation::where('inactive', false)->get(['id', 'code', 'name']),
            'shipping_companies' => ShippingCompany::all(['id', 'name']),
            'tax_groups'         => TaxGroup::where('inactive', false)->get(['id', 'description']),
            'gl_accounts'        => GlAccount::where('inactive', false)
                                        ->orderBy('code')
                                        ->get(['code', 'name']),
        ], 'Form data retrieved');
    }

    public function byFarmer(string $farmerNo): JsonResponse
    {
        $customer = Customer::with(['branches', 'priceList', 'paymentTerm', 'creditStatus'])
            ->where('farmer_no', $farmerNo)
            ->first();

        if (! $customer) {
            return ApiResponse::notFound('No customer account linked to this farmer');
        }

        return ApiResponse::success($customer, 'Customer retrieved');
    }
}
