<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebitNoteReason extends Model
{
    protected $fillable = ['reason_code', 'reason_name', 'description', 'gl_account'];
}
