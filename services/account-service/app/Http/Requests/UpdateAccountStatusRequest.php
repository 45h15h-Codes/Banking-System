<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReturnsApiValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountStatusRequest extends FormRequest
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
            'status' => ['required', 'in:active,frozen,closed'],
        ];
    }
}
