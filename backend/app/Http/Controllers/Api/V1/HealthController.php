<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class HealthController extends Controller
{
    /**
     * @return array{ok: bool, version: string}
     */
    public function __invoke(): array
    {
        return [
            'ok' => true,
            'version' => config('app.version', '0.0.0'),
        ];
    }
}
