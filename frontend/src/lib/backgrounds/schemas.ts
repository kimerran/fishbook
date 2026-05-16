import { z } from 'zod';

export const aspectRatios = ['16:9', '3:2', '1:1'] as const;
export type AspectRatio = (typeof aspectRatios)[number];

export const generateBackgroundSchema = z.object({
  prompt: z
    .string()
    .trim()
    .min(3, 'At least 3 characters')
    .max(500, 'At most 500 characters'),
  aspect_ratio: z.enum(aspectRatios).default('16:9'),
});

export type GenerateBackgroundInput = z.infer<typeof generateBackgroundSchema>;

export const UPLOAD_MIN_WIDTH = 1280;
export const UPLOAD_MIN_HEIGHT = 720;
export const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
export const UPLOAD_ALLOWED_MIME = [
  'image/jpeg',
  'image/png',
  'image/webp',
] as const;
