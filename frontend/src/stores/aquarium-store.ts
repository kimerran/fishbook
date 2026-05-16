import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

type Food = { id: string; x: number; y: number; createdAt: number };

export type AquariumStore = {
  food: Food[];
  hoveredFishId: string | null;
  paused: boolean;
  cameraOffset: { x: number; y: number };
  addFood: (x: number, y: number) => void;
  consumeFood: (id: string) => void;
  setHovered: (id: string | null) => void;
  togglePause: () => void;
};

export const useAquariumStore = create<AquariumStore>()(
  persist(
    (set) => ({
      food: [],
      hoveredFishId: null,
      paused: false,
      cameraOffset: { x: 0, y: 0 },
      addFood: (x, y) =>
        set((s) => ({
          food: [
            ...s.food,
            {
              id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
              x,
              y,
              createdAt: Date.now(),
            },
          ],
        })),
      consumeFood: (id) => set((s) => ({ food: s.food.filter((p) => p.id !== id) })),
      setHovered: (id) => set({ hoveredFishId: id }),
      togglePause: () => set((s) => ({ paused: !s.paused })),
    }),
    {
      name: 'fishbook:aquarium',
      storage: createJSONStorage(() => localStorage),
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      partialize: (s) => ({ paused: s.paused }) as any,
    },
  ),
);
