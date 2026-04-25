<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'type',
        'amount_minor',
        'balance_after_minor',
        'channel',
        'reference_no',
        'description',
        'status',
        'initiated_by',
        'related_transaction_id',
        'transfer_group_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

