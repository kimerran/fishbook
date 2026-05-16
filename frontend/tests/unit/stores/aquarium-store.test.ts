import { describe, it, expect, beforeEach } from 'vitest';
import { useAquariumStore } from '@/stores/aquarium-store';

describe('useAquariumStore', () => {
  beforeEach(() =>
    useAquariumStore.setState({
      food: [],
      hoveredFishId: null,
      paused: false,
      cameraOffset: { x: 0, y: 0 },
    }),
  );

  it('adds and consumes food', () => {
    const s = useAquariumStore.getState();
    s.addFood(10, 20);
    expect(useAquariumStore.getState().food).toHaveLength(1);
    const id = useAquariumStore.getState().food[0].id;
    s.consumeFood(id);
    expect(useAquariumStore.getState().food).toHaveLength(0);
  });

  it('sets and clears hovered fish', () => {
    useAquariumStore.getState().setHovered('f1');
    expect(useAquariumStore.getState().hoveredFishId).toBe('f1');
    useAquariumStore.getState().setHovered(null);
    expect(useAquariumStore.getState().hoveredFishId).toBeNull();
  });

  it('toggles pause', () => {
    useAquariumStore.getState().togglePause();
    expect(useAquariumStore.getState().paused).toBe(true);
  });
});
