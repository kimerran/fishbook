<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    type: 'object',
    required: ['username', 'email', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'username', type: 'string', pattern: '^[A-Za-z0-9_]{3,32}$', example: 'kimerran'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', minLength: 10, example: 'a-strong-pass-123!'),
        new OA\Property(property: 'password_confirmation', type: 'string'),
    ],
)]
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{3,32}$/', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
