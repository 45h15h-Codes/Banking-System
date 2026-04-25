<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'year',
        'current_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_value' => 'integer',
        ];
    }
}
