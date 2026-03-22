<?php

namespace App\Rules;

use App\Models\FiscalYear;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a date falls within the active (current, open) fiscal year.
 *
 * Usage in any controller:
 *   $request->validate(['tran_date' => ['required', 'date', new WithinFiscalYear]]);
 */
class WithinFiscalYear implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fy = FiscalYear::where('is_current', true)
            ->where('is_closed', false)
            ->first();

        if (! $fy) {
            $fail('No active fiscal year found. Please configure a current open fiscal year before posting transactions.');
            return;
        }

        $date = \Carbon\Carbon::parse($value)->startOfDay();

        if ($date->lt($fy->begin_date) || $date->gt($fy->end_date)) {
            $fail(
                "The :attribute ({$date->toDateString()}) is outside the active fiscal year " .
                "({$fy->begin_date->toDateString()} – {$fy->end_date->toDateString()})."
            );
        }
    }
}
