<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClaimUsernameRequest',
    type: 'object',
    required: ['username'],
    properties: [
        new OA\Property(property: 'username', type: 'string', pattern: '^[A-Za-z0-9_]{3,32}$'),
    ],
)]
class ClaimUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => [
                'required', 'string',
                'regex:/^[A-Za-z0-9_]{3,32}$/',
                'unique:users,username,'.($this->user()?->id ?? 'NULL'),
            ],
        ];
    }
}
