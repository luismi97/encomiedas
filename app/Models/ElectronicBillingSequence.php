<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicBillingSequence extends Model
{
    protected $fillable = [
        'branch_id',
        'document_type',
        'last_number',
    ];
}
