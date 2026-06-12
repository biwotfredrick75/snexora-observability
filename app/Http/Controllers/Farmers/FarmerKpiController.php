<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerKpiController extends Controller
{
    public function index(): JsonResponse
    {
        $today     = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // ── 1. Total active farmers ───────────────────────────────────────────
        $totalFarmers = DB::table('farmers')->where('status', 'active')->count();
        $newThisWeek  = DB::table('farmers')
            ->where('status', 'active')
            ->whereDate('created_at', '>=', $weekStart)
            ->count();

        // ── 2. Today's milk collection (litres) ───────────────────────────────
        $todayQty = (float) DB::table('milk_purchases')
            ->whereDate('invoice_date', $today)
            ->sum('total_qty');
        $yesterdayQty = (float) DB::table('milk_purchases')
            ->whereDate('invoice_date', $yesterday)
            ->sum('total_qty');

        // ── 3. Current avg milk price (normal, active today) ─────────────────
        $avgPrice = (float) DB::table('milk_prices')
            ->where('price_type', 'normal')
            ->where('date_from', '<=', $today)
            ->where('date_to', '>=', $today)
            ->avg('price');

        // ── 4. Pending farmer registrations ──────────────────────────────────
        $pendingFarmers = DB::table('farmers')->where('status', 'pending')->count();

        return ApiResponse::success([
            'total_farmers'  => $totalFarmers,
            'new_this_week'  => $newThisWeek,
            'today_qty'      => $todayQty,
            'yesterday_qty'  => $yesterdayQty,
            'avg_price'      => $avgPrice,
            'pending_farmers'=> $pendingFarmers,
        ], 'Farmer KPIs');
    }

    public function collectionChart(Request $request): JsonResponse
    {
        $dateTo   = $request->query('date_to')
            ? Carbon::parse($request->query('date_to'))->toDateString()
            : Carbon::today()->toDateString();

        $dateFrom = $request->query('date_from')
            ? Carbon::parse($request->query('date_from'))->toDateString()
            : Carbon::parse($dateTo)->subDays(29)->toDateString();

        // Cap at 365 days to avoid enormous payloads
        if (Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) > 365) {
            $dateFrom = Carbon::parse($dateTo)->subDays(364)->toDateString();
        }

        // Daily totals split by morning / evening shift category
        $raw = DB::table('milk_purchases as mp')
            ->leftJoin('milk_collection_shifts as s', 's.id', '=', 'mp.shift_id')
            ->where('mp.status', 'approved')
            ->whereBetween('mp.invoice_date', [$dateFrom, $dateTo])
            ->selectRaw("
                mp.invoice_date AS date,
                SUM(CASE WHEN LOWER(s.description) LIKE '%morning%' OR LOWER(s.description) LIKE '%day%'
                         THEN mp.total_qty ELSE 0 END) AS morning_qty,
                SUM(CASE WHEN LOWER(s.description) LIKE '%evening%' OR LOWER(s.description) LIKE '%night%'
                         THEN mp.total_qty ELSE 0 END) AS evening_qty,
                SUM(mp.total_qty) AS total_qty
            ")
            ->groupBy('mp.invoice_date')
            ->orderBy('mp.invoice_date')
            ->get()
            ->keyBy('date');

        // Fill every day in the range (no gaps)
        $chart = [];
        for ($d = Carbon::parse($dateFrom); $d->lte(Carbon::parse($dateTo)); $d->addDay()) {
            $key = $d->toDateString();
            $row = $raw->get($key);
            $chart[] = [
                'date'        => $key,
                'label'       => $d->format('d M'),
                'morning_qty' => $row ? round((float) $row->morning_qty, 3) : 0,
                'evening_qty' => $row ? round((float) $row->evening_qty, 3) : 0,
                'total_qty'   => $row ? round((float) $row->total_qty,   3) : 0,
            ];
        }

        return ApiResponse::success(['chart' => $chart], 'Collection chart');
    }
}
