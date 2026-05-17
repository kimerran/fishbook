<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\Event;

/**
 * Invokable Sentry beforeSend hook that redacts sensitive fields in
 * request headers, request body, and extra data before the event leaves
 * the process. See SPEC §16 and AGENT.md §4.
 *
 * Redaction (not drop) — keep the event for diagnosis; just blank the value.
 */
final class SentryEventScrubber
{
    /** @var string[] */
    private const SCRUB_KEYS = [
        'authorization',
        'cookie',
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'fal_api_key',
    ];

    private const REDACTED = '[REDACTED]';

    public function __invoke(Event $event): Event
    {
        $request = $event->getRequest();

        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = $this->scrub($request['headers']);
        }

        if (isset($request['data']) && is_array($request['data'])) {
            $request['data'] = $this->scrub($request['data']);
        }

        $event->setRequest($request);
        $event->setExtra($this->scrub($event->getExtra()));

        return $event;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scrub(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SCRUB_KEYS, true)) {
                $values[$key] = self::REDACTED;
            }
        }

        return $values;
    }
}
