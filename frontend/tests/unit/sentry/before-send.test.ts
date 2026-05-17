import { describe, expect, it } from 'vitest';

import { buildBeforeSend } from '@/lib/sentry/before-send';

type Event = Parameters<ReturnType<typeof buildBeforeSend>>[0];

describe('Sentry beforeSend scrubber', () => {
  it('redacts authorization header', () => {
    const send = buildBeforeSend();
    const ev = {
      request: { headers: { authorization: 'Bearer abc' } },
    } as unknown as Event;
    const out = send(ev, {} as never) as { request: { headers: { authorization: string } } };
    expect(out.request.headers.authorization).toBe('[REDACTED]');
  });

  it('redacts cookie header case-insensitively', () => {
    const send = buildBeforeSend();
    const ev = {
      request: { headers: { Cookie: 'session=xyz' } },
    } as unknown as Event;
    const out = send(ev, {} as never) as { request: { headers: Record<string, string> } };
    expect(out.request.headers.Cookie).toBe('[REDACTED]');
  });

  it('redacts password / token / api_key in body', () => {
    const send = buildBeforeSend();
    const ev = {
      request: {
        data: { password: 'hunter2', token: 't', api_key: 'k' },
      },
    } as unknown as Event;
    const out = send(ev, {} as never) as {
      request: { data: Record<string, string> };
    };
    expect(out.request.data.password).toBe('[REDACTED]');
    expect(out.request.data.token).toBe('[REDACTED]');
    expect(out.request.data.api_key).toBe('[REDACTED]');
  });

  it('redacts sensitive keys in extra', () => {
    const send = buildBeforeSend();
    const ev = {
      extra: { password: 'p', api_key: 'k', other: 'fine' },
    } as unknown as Event;
    const out = send(ev, {} as never) as { extra: Record<string, string> };
    expect(out.extra.password).toBe('[REDACTED]');
    expect(out.extra.api_key).toBe('[REDACTED]');
    expect(out.extra.other).toBe('fine');
  });

  it('returns the event (never drops)', () => {
    const send = buildBeforeSend();
    const ev = {} as unknown as Event;
    expect(send(ev, {} as never)).not.toBeNull();
  });
});
