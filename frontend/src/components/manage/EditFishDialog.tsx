'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  useBreedsQuery,
  useFishQuery,
  useUpdateFishMutation,
} from '@/hooks/use-fish-queries';
import { updateFishSchema, type UpdateFishInput } from '@/lib/fish/schemas';
import { useEffect } from 'react';

export function EditFishDialog({
  open,
  onOpenChange,
  fishId,
}: {
  open: boolean;
  onOpenChange: (b: boolean) => void;
  fishId: string | null;
}) {
  const { data: breeds } = useBreedsQuery();
  const { data: fishEnv } = useFishQuery(fishId);
  const fish = fishEnv?.data;
  const breed = breeds?.data?.find((b) => b.id === fish?.breed);
  const update = useUpdateFishMutation();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<UpdateFishInput>({
    resolver: zodResolver(updateFishSchema),
  });

  useEffect(() => {
    if (fish) {
      reset({
        nickname: fish.nickname,
        color_hex: fish.colorHex,
        size: fish.size,
      });
    }
  }, [fish, reset]);

  const onSubmit = (input: UpdateFishInput) => {
    if (!fishId) return;
    // breed deliberately excluded from payload — SPEC §2.2 immutability.
    update.mutate({ id: fishId, patch: input }, { onSuccess: () => onOpenChange(false) });
  };

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-[60]" />
        <Dialog.Content
          aria-describedby={undefined}
          className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-[60] w-[min(90vw,520px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20"
        >
          <Dialog.Title className="text-headline-md font-headline-md mb-6">
            Edit fish
          </Dialog.Title>
          {fish && (
            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
              <div>
                <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                  Breed
                </span>
                <div className="mt-2">
                  <span className="px-3 py-1 rounded-full bg-secondary-container/30 text-xs uppercase">
                    {fish.breed}
                  </span>
                </div>
              </div>
              <label className="block">
                <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                  Color
                </span>
                <input
                  type="color"
                  {...register('color_hex')}
                  className="block mt-1 h-8 w-16"
                />
              </label>
              <label className="block">
                <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                  Size
                </span>
                <input
                  type="range"
                  min={breed?.minSize ?? 1}
                  max={breed?.maxSize ?? 100}
                  {...register('size', { valueAsNumber: true })}
                  className="block w-full mt-1"
                />
              </label>
              <label className="block">
                <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                  Nickname
                </span>
                <input
                  type="text"
                  {...register('nickname')}
                  className="block w-full mt-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary"
                />
                {errors.nickname && (
                  <p className="text-error text-sm mt-1">{errors.nickname.message}</p>
                )}
              </label>
              <div className="flex justify-end gap-2 pt-2">
                <Dialog.Close className="px-6 py-2 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase">
                  Cancel
                </Dialog.Close>
                <button
                  type="submit"
                  disabled={update.isPending}
                  className="px-6 py-2 rounded-full bg-primary/30 backdrop-blur-md border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase active:scale-95 transition-all"
                >
                  Save
                </button>
              </div>
            </form>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
