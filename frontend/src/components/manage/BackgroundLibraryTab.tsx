'use client';

import {
  useBackgroundsQuery,
  useDeleteBackgroundMutation,
  useSelectBackgroundMutation,
} from '@/hooks/use-background-queries';
import type { BackgroundResource } from '@/lib/api-client';

export function BackgroundLibraryTab() {
  const { data, isLoading } = useBackgroundsQuery();
  const select = useSelectBackgroundMutation();
  const del = useDeleteBackgroundMutation();

  if (isLoading)
    return <p className="text-on-surface-variant">Loading...</p>;
  const rows = (data?.data ?? []) as BackgroundResource[];
  if (rows.length === 0)
    return (
      <p className="text-on-surface-variant">
        No backgrounds yet — upload or generate one.
      </p>
    );

  return (
    <ul className="grid grid-cols-2 md:grid-cols-3 gap-3">
      {rows.map((b) => (
        <li
          key={b.id}
          className="group relative rounded-xl overflow-hidden border border-white/20"
        >
          <button
            type="button"
            aria-label="Select background"
            onClick={() => select.mutate(b.id)}
            className="block w-full aspect-video"
          >
            <img
              src={b.signedUrl}
              alt=""
              role="presentation"
              loading="lazy"
              className="w-full h-full object-cover"
            />
          </button>
          {b.isActive && (
            <span className="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-primary/40 backdrop-blur-md text-[11px] uppercase tracking-[0.1em]">
              Active
            </span>
          )}
          <button
            type="button"
            aria-label="Delete background"
            onClick={() => del.mutate(b.id)}
            className="absolute bottom-2 right-2 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity px-2 py-1 rounded-full bg-error/30 text-[11px] uppercase"
          >
            Delete
          </button>
        </li>
      ))}
    </ul>
  );
}
