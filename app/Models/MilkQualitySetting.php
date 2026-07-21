<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkQualitySetting extends Model
{
    protected $table = 'milk_quality_settings';

    protected $fillable = [
        'min_butterfat_percent', 'min_density', 'max_density',
        'reject_on_alcohol_positive', 'reject_on_adulteration_positive', 'reject_on_abnormal_smell',
        'enable_smell_test', 'enable_alcohol_test', 'enable_density_test',
        'enable_butterfat_test', 'enable_adulteration_test',
    ];

    protected $casts = [
        'min_butterfat_percent'            => 'float',
        'min_density'                      => 'float',
        'max_density'                      => 'float',
        'reject_on_alcohol_positive'       => 'boolean',
        'reject_on_adulteration_positive'  => 'boolean',
        'reject_on_abnormal_smell'         => 'boolean',
        'enable_smell_test'                => 'boolean',
        'enable_alcohol_test'              => 'boolean',
        'enable_density_test'              => 'boolean',
        'enable_butterfat_test'            => 'boolean',
        'enable_adulteration_test'         => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
