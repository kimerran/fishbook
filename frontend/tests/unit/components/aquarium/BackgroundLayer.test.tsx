import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/react';
import { BackgroundLayer } from '@/components/aquarium/BackgroundLayer';

vi.mock('@/hooks/use-background-queries', () => ({
  useActiveBackgroundQuery: vi.fn(),
}));

import { useActiveBackgroundQuery } from '@/hooks/use-background-queries';

describe('BackgroundLayer', () => {
  beforeEach(() => {
    (useActiveBackgroundQuery as unknown as ReturnType<typeof vi.fn>).mockReset();
    // Reduced-motion media query default: off.
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: (q: string) => ({
        matches: false,
        media: q,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      }),
    });
  });

  it('renders the signed URL when an active background exists', () => {
    (useActiveBackgroundQuery as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
      id: '1',
      signedUrl: 'https://s3/abc?X-Amz-Signature=zzz',
      width: 1920,
      height: 1080,
      isActive: true,
    });
    const { container } = render(<BackgroundLayer />);
    expect(container.querySelector('img')?.getAttribute('src')).toContain('X-Amz-Signature');
  });

  it('renders fallback gradient when none', () => {
    (useActiveBackgroundQuery as unknown as ReturnType<typeof vi.fn>).mockReturnValue(null);
    const { container } = render(<BackgroundLayer />);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-testid="bg-fallback"]')).not.toBeNull();
  });
});
