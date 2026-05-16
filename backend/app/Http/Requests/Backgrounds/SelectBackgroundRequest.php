<?php

namespace App\Http\Requests\Backgrounds;

use Illuminate\Foundation\Http\FormRequest;

class SelectBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bg = $this->route('background');

        return $bg !== null && (bool) ($this->user()?->can('update', $bg));
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
