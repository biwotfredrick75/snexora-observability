<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteReason extends Model
{
    protected $fillable = ['reason_code', 'reason_name', 'description'];
}
