<?php

namespace App\Http\Requests\Backgrounds;

use App\Models\Background;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GenerateBackgroundRequest',
    type: 'object',
    required: ['prompt'],
    properties: [
        new OA\Property(property: 'prompt', type: 'string', minLength: 3, maxLength: 500),
        new OA\Property(property: 'aspect_ratio', type: 'string', enum: ['16:9', '3:2', '1:1'], default: '16:9'),
    ],
)]
class GenerateBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Background::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('aspect_ratio') === null) {
            $this->merge(['aspect_ratio' => '16:9']);
        }
        if (is_string($this->input('prompt'))) {
            $this->merge(['prompt' => trim((string) $this->input('prompt'))]);
        }
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:500'],
            'aspect_ratio' => ['nullable', 'in:16:9,3:2,1:1'],
        ];
    }
}
