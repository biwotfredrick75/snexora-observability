<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Farmer;
use App\Models\FarmerBank;
use App\Models\MilkRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Farmer::with(['bank', 'route'])
            ->orderBy('full_name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qb) use ($q) {
                $qb->where('full_name', 'like', "%$q%")
                   ->orWhere('farmer_no', 'like', "%$q%")
                   ->orWhere('id_no', 'like', "%$q%");
            });
        }

        return ApiResponse::success($query->get(), 'Farmers retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'            => 'required|string|max:200',
            'short_name'           => 'nullable|string|max:100',
            'address'              => 'nullable|string',
            'kra_pin'              => 'nullable|string|max:50',
            'id_no'                => 'nullable|string|max:50',
            'currency'             => 'nullable|string|max:10',
            'payment_terms_id'     => 'nullable|integer',
            'status'               => 'nullable|string|max:30',
            'phone'                => 'nullable|string|max:30',
            'email'                => 'nullable|email|max:150',
            'bank_id'              => 'nullable|integer',
            'account_no'           => 'nullable|string|max:50',
            'gender'               => 'nullable|string|max:10',
            'dob'                  => 'nullable|date',
            'route_id'             => 'nullable|integer',
            'village'              => 'nullable|string|max:100',
            'county'               => 'nullable|string|max:100',
            'sub_county'           => 'nullable|string|max:100',
            'location_area'        => 'nullable|string|max:100',
            'sub_location'         => 'nullable|string|max:100',
            'nearest_town'         => 'nullable|string|max:100',
            'spouse_name'          => 'nullable|string|max:200',
            'spouse_phone'         => 'nullable|string|max:30',
            'spouse_account_no'    => 'nullable|string|max:50',
            'next_of_kin_name'     => 'nullable|string|max:200',
            'next_of_kin_account_no' => 'nullable|string|max:50',
            'member_no'            => 'nullable|string|max:50',
            'related_member_nos'   => 'nullable|string|max:500',
            'director_name'        => 'nullable|string|max:200',
            'latitude'             => 'nullable|numeric|between:-90,90',
            'longitude'            => 'nullable|numeric|between:-180,180',
        ]);

        $data['farmer_no'] = $this->generateFarmerNo();
        $data['currency']  = $data['currency'] ?? 'KES';
        $data['status']    = $data['status']   ?? 'pending';

        $farmer = Farmer::create($data);
        return ApiResponse::created($farmer->load(['bank', 'route']), 'Farmer created');
    }

    public function show(int $id): JsonResponse
    {
        $farmer = Farmer::with(['bank', 'route', 'contacts'])->findOrFail($id);
        return ApiResponse::success($farmer, 'Farmer retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $farmer = Farmer::findOrFail($id);
        $data = $request->validate([
            'full_name'            => 'sometimes|required|string|max:200',
            'short_name'           => 'nullable|string|max:100',
            'address'              => 'nullable|string',
            'kra_pin'              => 'nullable|string|max:50',
            'id_no'                => 'nullable|string|max:50',
            'currency'             => 'nullable|string|max:10',
            'payment_terms_id'     => 'nullable|integer',
            'status'               => 'nullable|string|max:30',
            'phone'                => 'nullable|string|max:30',
            'email'                => 'nullable|email|max:150',
            'bank_id'              => 'nullable|integer',
            'account_no'           => 'nullable|string|max:50',
            'gender'               => 'nullable|string|max:10',
            'dob'                  => 'nullable|date',
            'route_id'             => 'nullable|integer',
            'village'              => 'nullable|string|max:100',
            'county'               => 'nullable|string|max:100',
            'sub_county'           => 'nullable|string|max:100',
            'location_area'        => 'nullable|string|max:100',
            'sub_location'         => 'nullable|string|max:100',
            'nearest_town'         => 'nullable|string|max:100',
            'spouse_name'          => 'nullable|string|max:200',
            'spouse_phone'         => 'nullable|string|max:30',
            'spouse_account_no'    => 'nullable|string|max:50',
            'next_of_kin_name'     => 'nullable|string|max:200',
            'next_of_kin_account_no' => 'nullable|string|max:50',
            'member_no'            => 'nullable|string|max:50',
            'related_member_nos'   => 'nullable|string|max:500',
            'director_name'        => 'nullable|string|max:200',
            'latitude'             => 'nullable|numeric|between:-90,90',
            'longitude'            => 'nullable|numeric|between:-180,180',
        ]);
        $farmer->fill($data)->save();
        return ApiResponse::updated($farmer->fresh()->load(['bank', 'route']), 'Farmer updated');
    }

    public function destroy(int $id): JsonResponse
    {
        Farmer::findOrFail($id)->delete();
        return ApiResponse::deleted('Farmer deleted');
    }

    public function approve(int $id): JsonResponse
    {
        $farmer = Farmer::findOrFail($id);
        $farmer->status = 'active';
        $farmer->save();
        return ApiResponse::updated($farmer->fresh(), 'Farmer approved');
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->get('q', ''));
        $query = Farmer::orderBy('full_name');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active');
        }

        if ($request->filled('route_id')) {
            $query->where('route_id', (int) $request->route_id);
        }

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('full_name', 'like', "%$q%")
                   ->orWhere('farmer_no', 'like', "%$q%")
                   ->orWhere('member_no', 'like', "%$q%");
            });
        }

        $farmers = $query->limit(30)->get(['id', 'farmer_no', 'full_name', 'member_no']);

        return ApiResponse::success($farmers, 'Farmers search results');
    }

    /**
     * Lightweight list for map plotting — only farmers with coordinates set
     * (most won't, since this is opt-in), capped so a 20k+ farmer base can't
     * blow up the map/response.
     */
    public function mapLocations(): JsonResponse
    {
        $farmers = Farmer::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('full_name')
            ->limit(2000)
            ->get(['id', 'farmer_no', 'full_name', 'status', 'latitude', 'longitude']);

        return ApiResponse::success($farmers, 'Farmer locations retrieved');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'banks'  => FarmerBank::orderBy('name')->get(),
            'routes' => MilkRoute::orderBy('route_name')->get(),
        ], 'Form data retrieved');
    }

    private function generateFarmerNo(): string
    {
        $last = Farmer::orderByDesc('id')->value('farmer_no');
        $num  = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;
        return 'FRM-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
