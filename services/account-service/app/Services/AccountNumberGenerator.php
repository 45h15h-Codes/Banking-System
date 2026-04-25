<?php

namespace App\Services;

use App\Models\AccountSequence;
use Illuminate\Support\Facades\DB;

class AccountNumberGenerator
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $year = (int) now()->format('Y');

            $sequence = AccountSequence::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['year' => $year],
                    ['current_value' => 0],
                );

            $sequence->current_value++;
            $sequence->save();

            return sprintf('BANK%d%08d', $year, $sequence->current_value);
        });
    }
}
