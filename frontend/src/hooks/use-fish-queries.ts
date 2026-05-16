'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fishesApi } from '@/lib/fish/api';
import type { CreateFishInput, UpdateFishInput } from '@/lib/fish/schemas';
import type {
  ListFishesSortEnum,
  ListFishesDirectionEnum,
} from '@/lib/api-client';

export type FishListParams = Partial<{
  search: string;
  breed: string;
  color: string;
  sort: ListFishesSortEnum;
  direction: ListFishesDirectionEnum;
  page: number;
  perPage: number;
}>;

export function useFishesQuery(params: FishListParams = {}) {
  return useQuery({
    queryKey: ['fishes', 'list', params],
    queryFn: () => fishesApi.listFishes(params),
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });
}

export function useFishQuery(id: string | null) {
  return useQuery({
    queryKey: ['fishes', 'one', id],
    queryFn: () => fishesApi.getFish({ fish: Number(id) }),
    enabled: id !== null,
  });
}

export function useBreedsQuery() {
  return useQuery({
    queryKey: ['fishes', 'breeds'],
    queryFn: () => fishesApi.listBreeds(),
    staleTime: Infinity,
  });
}

export function useCreateFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateFishInput) =>
      fishesApi.createFish({
        storeFishRequest: {
          nickname: input.nickname,
          breed: input.breed,
          colorHex: input.color_hex,
          size: input.size,
        },
      }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes', 'list'] }),
  });
}

export function useUpdateFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: { id: string; patch: UpdateFishInput }) =>
      fishesApi.updateFish({
        fish: Number(id),
        updateFishRequest: {
          nickname: patch.nickname,
          colorHex: patch.color_hex,
          size: patch.size,
        },
      }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes', 'list'] }),
  });
}

export function useDeleteFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fishesApi.deleteFish({ fish: Number(id) }),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['fishes', 'list'] });
      const snapshots = qc.getQueriesData({ queryKey: ['fishes', 'list'] });
      for (const [key, val] of snapshots) {
        if (val && typeof val === 'object' && 'data' in (val as Record<string, unknown>)) {
          const v = val as { data: Array<{ id: string }> };
          qc.setQueryData(key, { ...v, data: v.data.filter((f) => f.id !== id) });
        }
      }
      return { snapshots };
    },
    onError: (_e, _id, ctx) => {
      ctx?.snapshots.forEach(([key, val]) => qc.setQueryData(key, val));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes', 'list'] }),
  });
}
