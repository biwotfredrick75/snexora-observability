<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkQualitySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkQualitySettingController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success(MilkQualitySetting::current(), 'Quality control settings retrieved');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'min_butterfat_percent'           => 'sometimes|numeric|min:0|max:100',
            'min_density'                     => 'sometimes|numeric|min:0.9|max:1.2',
            'max_density'                     => 'sometimes|numeric|min:0.9|max:1.2',
            'reject_on_alcohol_positive'      => 'sometimes|boolean',
            'reject_on_adulteration_positive' => 'sometimes|boolean',
            'reject_on_abnormal_smell'        => 'sometimes|boolean',
            'enable_smell_test'               => 'sometimes|boolean',
            'enable_alcohol_test'             => 'sometimes|boolean',
            'enable_density_test'             => 'sometimes|boolean',
            'enable_butterfat_test'           => 'sometimes|boolean',
            'enable_adulteration_test'        => 'sometimes|boolean',
        ]);

        $settings = MilkQualitySetting::current();
        $settings->fill($data)->save();

        return ApiResponse::updated($settings->fresh(), 'Quality control settings updated');
    }
}
