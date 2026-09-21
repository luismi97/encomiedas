<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ElectronicBillingSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'branch_id',
        'document_type',
        'last_number',
    ];
}
