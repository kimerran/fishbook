<?php

namespace App\Http\Requests\Fish;

use App\Services\Fish\BreedCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreFishRequest',
    type: 'object',
    required: ['nickname', 'breed', 'color_hex', 'size'],
    properties: [
        new OA\Property(property: 'nickname', type: 'string', minLength: 1, maxLength: 40),
        new OA\Property(property: 'breed', type: 'string', example: 'guppy'),
        new OA\Property(property: 'color_hex', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$', example: '#FF6B9D'),
        new OA\Property(property: 'size', type: 'integer', minimum: 1, maximum: 100),
    ],
)]
class StoreFishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nickname'))) {
            $this->merge(['nickname' => trim($this->input('nickname'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'min:1', 'max:40'],
            'breed' => ['required', 'string', $this->breedRule()],
            'color_hex' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size' => ['required', 'integer', $this->sizeRule()],
        ];
    }

    private function breedRule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (! is_string($value) || app(BreedCatalog::class)->find($value) === null) {
                    $fail('Unknown breed.');
                }
            }
        };
    }

    private function sizeRule(): ValidationRule
    {
        $breed = (string) $this->input('breed');

        return new class($breed) implements ValidationRule
        {
            public function __construct(private readonly string $breed) {}

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $b = app(BreedCatalog::class)->find($this->breed);
                if ($b === null) {
                    return; // breed rule handles it
                }
                if (! is_int($value) || $value < $b['min_size'] || $value > $b['max_size']) {
                    $fail("Size must be between {$b['min_size']} and {$b['max_size']} for {$this->breed}.");
                }
            }
        };
    }
}
