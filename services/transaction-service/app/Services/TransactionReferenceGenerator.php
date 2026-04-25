<?php

namespace App\Services;

use Illuminate\Support\Str;

class TransactionReferenceGenerator
{
    public function generate(): string
    {
        return 'TXN'.now()->format('Ymd').strtoupper(Str::random(8));
    }
}

