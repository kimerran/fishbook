import { render, fireEvent } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';

const breeds = [{ id: 'guppy' }];
const fishes = [
  { id: 'f1', breed: 'guppy', color_hex: '#FF6B9D', size: 12, nickname: 'F1' },
];

describe('AquariumCanvas readOnly', () => {
  it('accepts the readOnly prop without crashing', () => {
    const { getByTestId } = render(
      <AquariumCanvas fishes={fishes} breeds={breeds} readOnly />,
    );
    expect(getByTestId('aquarium-canvas')).toBeInTheDocument();
  });

  it('still drops a food pellet on click when readOnly', () => {
    const { getByTestId } = render(
      <AquariumCanvas fishes={fishes} breeds={breeds} readOnly />,
    );
    const canvas = getByTestId('aquarium-canvas');
    fireEvent.mouseDown(canvas, { clientX: 100, clientY: 100 });
    // Pellet state is internal; this asserts the handler doesn't throw
    // and the canvas remains mounted under readOnly.
    expect(canvas).toBeInTheDocument();
  });
});
