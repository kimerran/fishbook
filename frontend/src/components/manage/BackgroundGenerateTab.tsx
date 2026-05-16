'use client';

import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  aspectRatios,
  generateBackgroundSchema,
  type GenerateBackgroundInput,
} from '@/lib/backgrounds/schemas';
import { useGenerateBackgroundMutation } from '@/hooks/use-background-queries';

const QUICK_PICKS = [
  'a calm coral reef at dusk, soft caustic light',
  'a kelp forest with sunbeams, photographic',
  'a deep-sea trench, bioluminescent particles',
];

export function BackgroundGenerateTab() {
  const generate = useGenerateBackgroundMutation();
  const {
    register,
    handleSubmit,
    setValue,
    control,
    formState: { errors },
  } = useForm<GenerateBackgroundInput>({
    resolver: zodResolver(generateBackgroundSchema),
    defaultValues: { prompt: '', aspect_ratio: '16:9' },
  });

  const onSubmit = (input: GenerateBackgroundInput) => generate.mutate(input);

  return (
    <div className="space-y-4">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <label className="block">
          <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
            Prompt
          </span>
          <textarea
            {...register('prompt')}
            rows={3}
            className="block w-full mt-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary"
          />
          {errors.prompt && (
            <p role="alert" className="text-error text-sm mt-1">
              {errors.prompt.message}
            </p>
          )}
        </label>
        <div className="flex flex-wrap gap-2">
          {QUICK_PICKS.map((q) => (
            <button
              key={q}
              type="button"
              onClick={() => setValue('prompt', q, { shouldValidate: true })}
              className="px-3 py-1 rounded-full bg-white/20 text-xs"
            >
              {q.slice(0, 30)}...
            </button>
          ))}
        </div>
        <Controller
          control={control}
          name="aspect_ratio"
          render={({ field }) => (
            <div className="flex gap-2">
              {aspectRatios.map((r) => (
                <button
                  key={r}
                  type="button"
                  onClick={() => field.onChange(r)}
                  className={`px-3 py-1 rounded-full border ${
                    field.value === r
                      ? 'bg-primary/30 border-white/40'
                      : 'bg-white/20 border-white/20'
                  }`}
                >
                  {r}
                </button>
              ))}
            </div>
          )}
        />
        <button
          type="submit"
          disabled={generate.isPending}
          className="px-6 py-2 rounded-full bg-primary/30 backdrop-blur-md border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase"
        >
          Generate
        </button>
      </form>
      {generate.isPending && (
        <div
          role="status"
          aria-live="polite"
          className="p-6 rounded-xl bg-white/30 backdrop-blur-md border border-white/20 text-center"
        >
          <p className="font-headline-md text-headline-md">Painting your aquarium...</p>
          <p className="text-on-surface-variant mt-2">This can take up to a minute.</p>
        </div>
      )}
      {generate.isError && (
        <p role="alert" className="text-error">
          {(generate.error as Error)?.message}
        </p>
      )}
    </div>
  );
}
