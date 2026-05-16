'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { backgroundsApi, uploadBackground } from '@/lib/backgrounds/api';
import type { GenerateBackgroundInput } from '@/lib/backgrounds/schemas';
import type { BackgroundResource } from '@/lib/api-client';

type BackgroundList = { data: BackgroundResource[]; links?: unknown; meta?: unknown };

const LIST_KEY = ['backgrounds', 'list'] as const;

export function useBackgroundsQuery() {
  return useQuery({
    queryKey: LIST_KEY,
    queryFn: () => backgroundsApi.listBackgrounds(),
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });
}

export function useActiveBackgroundQuery(): BackgroundResource | null {
  const { data } = useBackgroundsQuery();
  const rows = (data as BackgroundList | undefined)?.data ?? [];
  return rows.find((b) => b.isActive) ?? null;
}

export function useUploadBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadBackground(file),
    onSettled: () => qc.invalidateQueries({ queryKey: LIST_KEY }),
  });
}

export function useGenerateBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: GenerateBackgroundInput) =>
      backgroundsApi.generateBackground({
        generateBackgroundRequest: {
          prompt: input.prompt,
          aspectRatio: input.aspect_ratio,
        },
      }),
    onSettled: () => qc.invalidateQueries({ queryKey: LIST_KEY }),
  });
}

export function useSelectBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) =>
      backgroundsApi.selectBackground({ background: id }),
    onMutate: async (id: string) => {
      await qc.cancelQueries({ queryKey: LIST_KEY });
      const snapshots = qc.getQueriesData({ queryKey: LIST_KEY });
      for (const [key, val] of snapshots) {
        if (val && typeof val === 'object' && 'data' in (val as Record<string, unknown>)) {
          const v = val as BackgroundList;
          qc.setQueryData(key, {
            ...v,
            data: v.data.map((b) => ({ ...b, isActive: b.id === id })),
          });
        }
      }
      return { snapshots };
    },
    onError: (_e, _id, ctx) => {
      ctx?.snapshots.forEach(([key, val]) => qc.setQueryData(key, val));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: LIST_KEY }),
  });
}

export function useDeleteBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) =>
      backgroundsApi.deleteBackground({ background: id }),
    onMutate: async (id: string) => {
      await qc.cancelQueries({ queryKey: LIST_KEY });
      const snapshots = qc.getQueriesData({ queryKey: LIST_KEY });
      for (const [key, val] of snapshots) {
        if (val && typeof val === 'object' && 'data' in (val as Record<string, unknown>)) {
          const v = val as BackgroundList;
          qc.setQueryData(key, { ...v, data: v.data.filter((b) => b.id !== id) });
        }
      }
      return { snapshots };
    },
    onError: (_e, _id, ctx) => {
      ctx?.snapshots.forEach(([key, val]) => qc.setQueryData(key, val));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: LIST_KEY }),
  });
}
