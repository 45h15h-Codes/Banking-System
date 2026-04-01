<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycAuditLog extends Model
{
    use HasFactory, HasUuids;

    public $updated_at = false; // Immutable ledger -> no updates

    protected $fillable = [
        'customer_id',
        'action',
        'old_value',
        'new_value',
        'performed_by',
        'remarks',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
