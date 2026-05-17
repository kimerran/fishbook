<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps SPEC §16 security headers onto every response.
 *
 * Production: enforced Content-Security-Policy + Strict-Transport-Security.
 * Non-production: report-only CSP + MinIO localhost in img-src; no HSTS.
 *
 * Registered globally in bootstrap/app.php → appended so it runs after CORS
 * and after exception rendering.
 */
final class SecurityHeaders
{
    /** @return array<string, string[]> */
    private function cspDirectives(bool $isProd): array
    {
        $imgSrc = ["'self'", 'https://*.r2.cloudflarestorage.com', 'https://*.s3.amazonaws.com', 'data:'];
        if (! $isProd) {
            $imgSrc[] = 'http://localhost:9000';
        }

        $scriptSrc = ["'self'"];
        if (! $isProd) {
            // Next.js dev mode + Vitest happy-dom need eval/inline.
            $scriptSrc[] = "'unsafe-eval'";
            $scriptSrc[] = "'unsafe-inline'";
        }

        $connectSrc = ["'self'"];
        $connectSrc[] = $isProd ? 'https://api.fishbook.neri.ph' : 'http://localhost:8000';

        return [
            'default-src' => ["'self'"],
            'script-src' => $scriptSrc,
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => $imgSrc,
            'connect-src' => $connectSrc,
            'font-src' => ["'self'", 'data:'],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];
    }

    private function buildCsp(bool $isProd): string
    {
        $parts = [];
        foreach ($this->cspDirectives($isProd) as $directive => $values) {
            $parts[] = $directive.' '.implode(' ', $values);
        }

        return implode('; ', $parts);
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $isProd = app()->environment('production');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = $this->buildCsp($isProd);
        if ($isProd) {
            $response->headers->set('Content-Security-Policy', $csp);
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        } else {
            $response->headers->set('Content-Security-Policy-Report-Only', $csp);
        }

        return $response;
    }
}
