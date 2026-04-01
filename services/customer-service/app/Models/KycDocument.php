<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'document_type',
        'file_path',
        'status',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
