<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReturnsApiValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawRequest extends FormRequest
{
    use ReturnsApiValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'channel' => ['nullable', 'in:cash,upi,neft,imps,internal,razorpay'],
            'description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

