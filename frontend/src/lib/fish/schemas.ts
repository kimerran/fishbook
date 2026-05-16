import { z } from 'zod';

export const colorHexSchema = z.string().regex(/^#[0-9A-Fa-f]{6}$/, 'Must be #RRGGBB');

export const createFishSchema = z.object({
  nickname: z.string().trim().min(1).max(40),
  breed: z.string(),
  color_hex: colorHexSchema,
  size: z.number().int().min(1).max(100),
});

export const updateFishSchema = z.object({
  nickname: z.string().trim().min(1).max(40).optional(),
  color_hex: colorHexSchema.optional(),
  size: z.number().int().min(1).max(100).optional(),
});

export type CreateFishInput = z.infer<typeof createFishSchema>;
export type UpdateFishInput = z.infer<typeof updateFishSchema>;
