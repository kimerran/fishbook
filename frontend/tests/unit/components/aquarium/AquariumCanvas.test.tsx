import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';

describe('AquariumCanvas', () => {
  it('mounts a canvas and starts a RAF', () => {
    const raf = vi.spyOn(globalThis, 'requestAnimationFrame');
    const { container, rerender } = render(<AquariumCanvas fishes={[]} breeds={[]} />);
    expect(container.querySelector('canvas')).not.toBeNull();
    expect(raf).toHaveBeenCalled();
    const callsBefore = raf.mock.calls.length;
    // Parent re-render must not restart the loop.
    rerender(<AquariumCanvas fishes={[]} breeds={[]} />);
    expect(raf.mock.calls.length).toBe(callsBefore);
  });
});
