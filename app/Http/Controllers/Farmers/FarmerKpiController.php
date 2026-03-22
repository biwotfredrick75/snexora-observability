<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
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
}
