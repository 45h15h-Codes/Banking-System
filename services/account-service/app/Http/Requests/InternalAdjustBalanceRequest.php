<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReturnsApiValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class InternalAdjustBalanceRequest extends FormRequest
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
            'delta_minor' => ['required', 'integer', 'not_in:0'],
        ];
    }
}

