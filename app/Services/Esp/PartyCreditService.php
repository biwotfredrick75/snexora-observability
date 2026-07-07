<?php

namespace App\Services\Esp;

use App\Models\EspSale;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Computes available ESP (external agrovet/service-provider) invoicing credit
 * for the three billable party types. Formula per type:
 *
 *   farmer      = this month's milk value      - already-invoiced ESP sales - current debts
 *   employee    = gross salary (current period) - already-invoiced ESP sales - current debts
 *   transporter = this month's milk hauled value - already-invoiced ESP sales - current debts
 *
 * "transporter" = grader (inventory_locations row, type grader/vendor) — there is
 * no separate transporter entity, graders are the ones who haul/collect milk.
 */
class PartyCreditService
{
    public function score(string $partyType, int $partyId): array
    {
        return match ($partyType) {
            'farmer'      => $this->farmerScore($partyId),
            'employee'    => $this->employeeScore($partyId),
            'transporter' => $this->transporterScore($partyId),
            default       => throw new InvalidArgumentException("Unknown party_type: {$partyType}"),
        };
    }

    public function farmerScore(int $farmerId): array
    {
        $farmer = DB::table('farmers')->where('id', $farmerId)->first();
        if (! $farmer) {
            throw new InvalidArgumentException("Farmer #{$farmerId} not found");
        }

        $milkValue = (float) DB::table('milk_purchase_items')
            ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
            ->where('milk_purchases.route_id', $farmer->route_id)
            ->whereMonth('milk_purchases.invoice_date', now()->month)
            ->whereYear('milk_purchases.invoice_date', now()->year)
            ->where('milk_purchase_items.farmer_id', $farmerId)
            ->sum('milk_purchase_items.total_price');

        $alreadyInvoiced = (float) EspSale::forParty('farmer', $farmerId)->notYetDeducted()->sum('total_amount');

        $checkoffThisMonth = (float) DB::table('farmer_checkoff_entries')
            ->where('farmer_id', $farmerId)
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->sum('amount');

        $carryForward = (float) DB::table('farmer_carry_forwards')
            ->where('farmer_id', $farmerId)
            ->where('to_month', now()->month)
            ->where('to_year', now()->year)
            ->sum('amount');

        $unpaidAdvances = (float) DB::table('farmer_payments')
            ->where('farmer_id', $farmerId)
            ->where('type', 'advance')
            ->whereMonth('date_paid', now()->month)
            ->whereYear('date_paid', now()->year)
            ->sum('amount_payment');

        return $this->buildResult(
            $milkValue,
            $alreadyInvoiced,
            $checkoffThisMonth + $carryForward + $unpaidAdvances
        );
    }

    public function employeeScore(int $employeeId): array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if (! $employee) {
            throw new InvalidArgumentException("Employee #{$employeeId} not found");
        }

        $latestItem = DB::table('payroll_items')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_items.payroll_period_id')
            ->where('payroll_items.employee_id', $employeeId)
            ->orderByDesc('payroll_periods.period_end')
            ->select('payroll_items.gross_pay', 'payroll_items.manual_deductions')
            ->first();

        $grossValue   = $latestItem ? (float) $latestItem->gross_pay : (float) $employee->basic_salary;
        $currentDebts = $latestItem ? (float) $latestItem->manual_deductions : 0.0;

        $alreadyInvoiced = (float) EspSale::forParty('employee', $employeeId)->notYetDeducted()->sum('total_amount');

        return $this->buildResult($grossValue, $alreadyInvoiced, $currentDebts);
    }

    public function transporterScore(int $graderId): array
    {
        $grader = DB::table('inventory_locations')
            ->where('id', $graderId)
            ->whereIn('type', ['grader', 'vendor'])
            ->first();
        if (! $grader) {
            throw new InvalidArgumentException("Grader/transporter #{$graderId} not found");
        }

        $rawMilkId = DB::table('items')
            ->where(fn ($q) => $q
                ->where('long_description', 'like', '%RAW MILK%')
                ->orWhere('description', 'like', '%RAW MILK%')
            )
            ->value('stock_id') ?? '0001';

        $startDate = now()->startOfMonth()->toDateString();
        $endDate   = now()->endOfMonth()->toDateString();

        $graderRates = DB::table('item_sales_prices')
            ->where('stock_id', $rawMilkId)
            ->where(fn ($q) => $q->whereNull('date_from')->orWhere('date_from', '<=', $endDate))
            ->where(fn ($q) => $q->whereNull('date_to')->orWhere('date_to', '>=', $startDate))
            ->get(['sales_type', 'grader_rate'])
            ->keyBy('sales_type');

        $rows = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->leftJoin('milk_routes as mr', 'mr.id', '=', 'mp.route_id')
            ->leftJoin('inventory_locations as dl', 'dl.id', '=', 'mr.location_id')
            ->where('mp.grader_id', $graderId)
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->where('mp.status', '!=', 'cancelled')
            ->selectRaw('dl.price_list as dest_price_list, SUM(mpi.quantity) as qty')
            ->groupBy('dl.price_list')
            ->get();

        $milkValue = 0.0;
        foreach ($rows as $row) {
            $rate = ($row->dest_price_list && isset($graderRates[$row->dest_price_list]))
                ? (float) $graderRates[$row->dest_price_list]->grader_rate
                : 0.0;
            $milkValue += (float) $row->qty * $rate;
        }

        $advances = (float) DB::table('grader_advances')
            ->where('grader_id', $graderId)
            ->whereBetween('advance_date', [$startDate, $endDate])
            ->sum('amount');

        $alreadyInvoiced = (float) EspSale::forParty('transporter', $graderId)->notYetDeducted()->sum('total_amount');

        return $this->buildResult($milkValue, $alreadyInvoiced, $advances);
    }

    private function buildResult(float $grossValue, float $alreadyInvoiced, float $currentDebts): array
    {
        $available = round($grossValue - $alreadyInvoiced - $currentDebts, 2);

        return [
            'gross_value'      => round($grossValue, 2),
            'already_invoiced' => round($alreadyInvoiced, 2),
            'current_debts'    => round($currentDebts, 2),
            'available_credit' => max(0.0, $available),
        ];
    }
}
