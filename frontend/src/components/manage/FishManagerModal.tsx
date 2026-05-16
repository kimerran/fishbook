'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import {
  useBreedsQuery,
  useDeleteFishMutation,
  useFishesQuery,
  type FishListParams,
} from '@/hooks/use-fish-queries';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { EditFishDialog } from './EditFishDialog';
import type { ListFishesSortEnum, ListFishesDirectionEnum } from '@/lib/api-client';

export function FishManagerModal({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (b: boolean) => void;
}) {
  const [search, setSearch] = useState('');
  const debounced = useDebouncedValue(search, 300);
  const [breed, setBreed] = useState<string | undefined>();
  const [sort, setSort] = useState<ListFishesSortEnum>('created_at');
  const [direction, setDirection] = useState<ListFishesDirectionEnum>('desc');
  const [editingId, setEditingId] = useState<string | null>(null);

  const { data: breeds } = useBreedsQuery();
  const params: FishListParams = {
    search: debounced || undefined,
    breed,
    sort,
    direction,
  };
  const { data } = useFishesQuery(params);
  const del = useDeleteFishMutation();

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-40" />
        <Dialog.Content
          aria-describedby={undefined}
          className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-40 w-[min(95vw,768px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20"
        >
          <Dialog.Title className="text-headline-md font-headline-md mb-4">
            Manage your sanctuary
          </Dialog.Title>
          <div className="flex gap-3 mb-4">
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search nickname"
              className="flex-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary"
            />
            <select
              value={breed ?? ''}
              onChange={(e) => setBreed(e.target.value || undefined)}
              className="bg-white/20 rounded-lg px-3 py-2"
            >
              <option value="">All breeds</option>
              {breeds?.data?.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.label}
                </option>
              ))}
            </select>
            <select
              value={`${sort}:${direction}`}
              onChange={(e) => {
                const [s, d] = e.target.value.split(':');
                setSort(s as ListFishesSortEnum);
                setDirection(d as ListFishesDirectionEnum);
              }}
              className="bg-white/20 rounded-lg px-3 py-2"
            >
              <option value="created_at:desc">Newest</option>
              <option value="created_at:asc">Oldest</option>
              <option value="name:asc">Name A→Z</option>
              <option value="name:desc">Name Z→A</option>
              <option value="size:asc">Size ↑</option>
              <option value="size:desc">Size ↓</option>
            </select>
          </div>
          <table className="w-full text-left">
            <thead>
              <tr className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                <th>Nickname</th>
                <th>Breed</th>
                <th>Color</th>
                <th>Size</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data?.data?.map((f) => (
                <tr key={f.id} className="border-t border-white/20">
                  <td className="py-2">{f.nickname}</td>
                  <td>
                    <span className="px-3 py-1 rounded-full bg-secondary-container/30 text-xs uppercase">
                      {f.breed}
                    </span>
                  </td>
                  <td>
                    <span
                      className="inline-block w-5 h-5 rounded-full border border-white/40"
                      style={{ background: f.colorHex }}
                    />
                  </td>
                  <td className="tabular-nums">{f.size}</td>
                  <td className="text-right">
                    <button
                      onClick={() => setEditingId(f.id)}
                      className="mr-2 text-primary"
                    >
                      Edit
                    </button>
                    <button onClick={() => del.mutate(f.id)} className="text-error">
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <EditFishDialog
            open={editingId !== null}
            onOpenChange={(b) => !b && setEditingId(null)}
            fishId={editingId}
          />
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
