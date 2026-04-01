<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'dob',
        'pan_number',
        'aadhaar_number',
        'address',
        'kyc_status',
    ];

    protected $hidden = [
        'pan_number',
        'aadhaar_number',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pan_number' => 'encrypted',
            'aadhaar_number' => 'encrypted',
            'dob' => 'date',
        ];
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }

    public function kycAuditLogs()
    {
        return $this->hasMany(KycAuditLog::class);
    }
}
