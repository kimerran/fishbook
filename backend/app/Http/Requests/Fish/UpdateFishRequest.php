<?php

namespace App\Http\Requests\Fish;

use App\Models\Fish;
use App\Services\Fish\BreedCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateFishRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'nickname', type: 'string', minLength: 1, maxLength: 40),
        new OA\Property(property: 'color_hex', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$'),
        new OA\Property(property: 'size', type: 'integer', minimum: 1, maximum: 100),
    ],
)]
class UpdateFishRequest extends FormRequest
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
            'nickname'  => ['sometimes', 'string', 'min:1', 'max:40'],
            'color_hex' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size'      => ['sometimes', 'integer', $this->sizeRule()],
            'breed'     => ['prohibited'],
        ];
    }

    private function sizeRule(): ValidationRule
    {
        /** @var Fish|null $fish */
        $fish = $this->route('fish');
        $breed = $fish?->breed ?? '';
        return new class($breed) implements ValidationRule {
            public function __construct(private readonly string $breed) {}

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $b = app(BreedCatalog::class)->find($this->breed);
                if ($b === null) {
                    return;
                }
                if (! is_int($value) || $value < $b['min_size'] || $value > $b['max_size']) {
                    $fail("Size must be between {$b['min_size']} and {$b['max_size']} for {$this->breed}.");
                }
            }
        };
    }
}
