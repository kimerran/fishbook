<?php

namespace App\Http\Requests\Backgrounds;

use App\Models\Background;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Layered MIME defense: this FormRequest's `mimes:` rule is the cheap
 * first gate (finfo). The deep gate lives in BackgroundImageProcessor
 * (Intervention v3 actually decodes the file). Both must pass.
 */
#[OA\Schema(
    schema: 'UploadBackgroundRequest',
    type: 'object',
    required: ['image'],
    properties: [new OA\Property(property: 'image', type: 'string', format: 'binary')],
)]
class UploadBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Background::class) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:5120', 'mimes:jpeg,png,webp']];
    }
}
