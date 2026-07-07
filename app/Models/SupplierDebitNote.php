<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDebitNote extends Model
{
    protected $table = 'supplier_debit_notes';

    protected $fillable = [
        'sdn_no', 'supplier_id', 'date', 'due_date', 'reference', 'supplier_ref',
        'terms', 'tax_group', 'dimension_id', 'dimension2_id',
        'items_total', 'gl_total', 'total', 'memo', 'status', 'raised_by',
    ];

    protected function casts(): array
    {
        return [
            'date'        => 'date',
            'due_date'    => 'date',
            'items_total' => 'float',
            'gl_total'    => 'float',
            'total'       => 'float',
        ];
    }

    public static function nextSdnNo(): string
    {
        $last = self::lockForUpdate()->max('id') ?? 0;
        return 'SDN' . str_pad($last + 1, 4, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplierId');
    }

    public function items()
    {
        return $this->hasMany(SupplierDebitNoteItem::class, 'sdn_id');
    }

    public function glItems()
    {
        return $this->hasMany(SupplierDebitNoteGlItem::class, 'sdn_id');
    }
}
