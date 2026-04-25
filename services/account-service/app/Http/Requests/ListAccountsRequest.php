<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReturnsApiValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class ListAccountsRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'customer_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,frozen,closed'],
        ];
    }
}
