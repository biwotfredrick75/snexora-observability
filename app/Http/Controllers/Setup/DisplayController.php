<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisplayController extends Controller
{
    private array $fields = [
        'prices_dec', 'qty_dec', 'rates_dec', 'percent_dec',
        'date_format', 'date_sep', 'tho_sep', 'dec_sep', 'use_date_picker',
        'save_report_selections', 'def_print_destination', 'def_print_orientation',
        'show_hints', 'show_gl', 'show_codes',
        'theme', 'page_size', 'startup_tab', 'print_profile',
        'rep_popup', 'graphic_links', 'sticky_doc_date',
        'query_size', 'transaction_days', 'language',
    ];

    /**
     * GET /api/setup/display
     * Returns the current user's display preferences.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = collect($this->fields)->mapWithKeys(fn($f) => [$f => $user->$f])->toArray();

        return ApiResponse::success($data, 'Display settings retrieved');
    }

    /**
     * POST /api/setup/display
     * Updates the current user's display preferences.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prices_dec'             => 'sometimes|integer|min:0|max:6',
            'qty_dec'                => 'sometimes|integer|min:0|max:6',
            'rates_dec'              => 'sometimes|integer|min:0|max:6',
            'percent_dec'            => 'sometimes|integer|min:0|max:6',
            'date_format'            => 'sometimes|integer|min:0|max:5',
            'date_sep'               => 'sometimes|integer|min:0|max:3',
            'tho_sep'                => 'sometimes|integer|min:0|max:2',
            'dec_sep'                => 'sometimes|integer|min:0|max:1',
            'use_date_picker'        => 'sometimes|boolean',
            'save_report_selections' => 'sometimes|integer|min:0',
            'def_print_destination'  => 'sometimes|integer|min:0|max:2',
            'def_print_orientation'  => 'sometimes|integer|min:0|max:1',
            'show_hints'             => 'sometimes|boolean',
            'show_gl'                => 'sometimes|boolean',
            'show_codes'             => 'sometimes|boolean',
            'theme'                  => 'sometimes|string|max:20',
            'page_size'              => 'sometimes|string|max:20',
            'startup_tab'            => 'sometimes|string|max:20',
            'print_profile'          => 'sometimes|string|max:30',
            'rep_popup'              => 'sometimes|boolean',
            'graphic_links'          => 'sometimes|boolean',
            'sticky_doc_date'        => 'sometimes|boolean',
            'query_size'             => 'sometimes|integer|min:1|max:500',
            'transaction_days'       => 'sometimes|integer|min:0',
            'language'               => 'sometimes|string|max:20',
        ]);

        $user = $request->user();
        $user->fill($validated)->save();

        $data = collect($this->fields)->mapWithKeys(fn($f) => [$f => $user->fresh()->$f])->toArray();

        return ApiResponse::success($data, 'Display settings updated');
    }
}
