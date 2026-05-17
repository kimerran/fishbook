import type { ErrorEvent, EventHint } from '@sentry/nextjs';

/**
 * Build a Sentry beforeSend hook that redacts sensitive fields from
 * request headers, request body, extra, and contexts before the event
 * leaves the process. Mirrors the backend `SentryEventScrubber`.
 * See SPEC §16 and AGENT.md §4.
 */
const SCRUB_KEYS = new Set(['authorization', 'cookie', 'password', 'token', 'api_key']);
const REDACTED = '[REDACTED]';

function scrubRecord(values: Record<string, unknown>): Record<string, unknown> {
  for (const key of Object.keys(values)) {
    if (SCRUB_KEYS.has(key.toLowerCase())) {
      values[key] = REDACTED;
    }
  }
  return values;
}

export function buildBeforeSend(): (
  event: ErrorEvent,
  hint: EventHint,
) => ErrorEvent | null {
  return (event) => {
    const req = event.request;
    if (req?.headers && typeof req.headers === 'object') {
      req.headers = scrubRecord(req.headers as Record<string, unknown>) as typeof req.headers;
    }
    if (req?.data && typeof req.data === 'object') {
      req.data = scrubRecord(req.data as Record<string, unknown>);
    }
    if (event.extra && typeof event.extra === 'object') {
      event.extra = scrubRecord(event.extra as Record<string, unknown>);
    }
    if (event.contexts && typeof event.contexts === 'object') {
      for (const ctxKey of Object.keys(event.contexts)) {
        const ctx = (event.contexts as Record<string, unknown>)[ctxKey];
        if (ctx && typeof ctx === 'object') {
          (event.contexts as Record<string, unknown>)[ctxKey] = scrubRecord(
            ctx as Record<string, unknown>,
          );
        }
      }
    }
    return event;
  };
}
