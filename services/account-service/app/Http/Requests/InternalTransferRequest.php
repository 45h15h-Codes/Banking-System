<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReturnsApiValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class InternalTransferRequest extends FormRequest
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
            'source_account_id' => ['required', 'uuid'],
            'destination_account_id' => ['required', 'uuid', 'different:source_account_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ];
    }
}
