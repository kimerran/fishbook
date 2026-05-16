'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';

export function useForkRepoMutation(owner: string, repo: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<{ added: number }> => {
      const r = await fetch(
        `/api/proxy/repos/${owner}/${repo}/fork-to-my-aquarium`,
        { method: 'POST' },
      );
      if (!r.ok) {
        throw new Error(`fork failed: ${r.status}`);
      }
      return (await r.json()) as { added: number };
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['fishes', 'list'] }),
  });
}
