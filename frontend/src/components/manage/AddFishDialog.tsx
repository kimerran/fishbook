'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useBreedsQuery, useCreateFishMutation } from '@/hooks/use-fish-queries';
import { createFishSchema, type CreateFishInput } from '@/lib/fish/schemas';
import clsx from 'clsx';

export function AddFishDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (b: boolean) => void;
}) {
  const { data: breeds } = useBreedsQuery();
  const create = useCreateFishMutation();
  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors },
  } = useForm<CreateFishInput>({
    resolver: zodResolver(createFishSchema),
    defaultValues: { nickname: '', breed: 'guppy', color_hex: '#FF6B9D', size: 12 },
  });
  const breedId = watch('breed');
  const breed = breeds?.data?.find((b) => b.id === breedId);

  const onSubmit = (input: CreateFishInput) =>
    create.mutate(input, { onSuccess: () => onOpenChange(false) });

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-50" />
        <Dialog.Content
          aria-describedby={undefined}
          className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-50 w-[min(90vw,520px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20"
        >
          <Dialog.Title className="text-headline-md font-headline-md mb-6">
            Curate a new fish
          </Dialog.Title>
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div>
              <label className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
                Breed
              </label>
              <div className="grid grid-cols-5 gap-2 mt-2">
                {breeds?.data?.map((b) => (
                  <Controller
                    key={b.id}
                    control={control}
                    name="breed"
                    render={({ field }) => (
                      <button
                        type="button"
                        onClick={() => field.onChange(b.id)}
                        className={clsx(
                          'p-2 rounded-lg border',
                          field.value === b.id
                            ? 'ring-2 ring-primary border-transparent'
                            : 'border-outline-variant',
                        )}
                      >
                        <img src={`/sprites/fish/${b.id}.svg`} alt={b.label} className="w-12 h-6" />
                      </button>
                    )}
                  />
                ))}
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
                disabled={create.isPending}
                className="px-6 py-2 rounded-full bg-primary/30 backdrop-blur-md border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase active:scale-95 transition-all"
              >
                Add fish
              </button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
