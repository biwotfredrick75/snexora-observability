<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppModuleConfig extends Model
{
    protected $primaryKey = 'module_id';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = ['module_id', 'label', 'description', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    // Canonical module list — mirrors module_registry.dart in the mobile app
    public static array $definitions = [
        ['module_id' => 'sales',            'label' => 'Sales',             'description' => 'Manage sales transactions and customer orders'],
        ['module_id' => 'inventory',        'label' => 'Inventory',         'description' => 'Track and manage product inventory'],
        ['module_id' => 'dairy',            'label' => 'Dairy',             'description' => 'Manage dairy collection and processing'],
        ['module_id' => 'collection_lite',  'label' => 'Collection Lite',   'description' => 'Fast, optimised milk collection for low-end devices'],
        ['module_id' => 'dispatch',         'label' => 'Dispatch',          'description' => 'Manage product dispatching and delivery'],
        ['module_id' => 'driver',           'label' => 'Driver',            'description' => 'Driver management and routing'],
        ['module_id' => 'merchandising',    'label' => 'Merchandising',     'description' => 'Product placement and merchandising activities'],
        ['module_id' => 'manufacturing',    'label' => 'Manufacturing',     'description' => 'Manage manufacturing processes and production'],
        ['module_id' => 'purchases',        'label' => 'Purchases',         'description' => 'Manage purchase orders and vendor interactions'],
        ['module_id' => 'location',         'label' => 'Location',          'description' => 'Location tracking and management'],
        ['module_id' => 'dairy_admin',      'label' => 'Dairy Admin',       'description' => 'Administrative functions for dairy operations'],
        ['module_id' => 'sales_management', 'label' => 'Sales Management',  'description' => 'Sales data analysis and reporting'],
        ['module_id' => 'analytics',        'label' => 'Analytics',         'description' => 'Business analytics and performance metrics'],
        ['module_id' => 'quality',          'label' => 'Quality Control',   'description' => 'Product quality assurance and control'],
    ];
}
