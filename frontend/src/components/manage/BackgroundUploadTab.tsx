'use client';

import { useState } from 'react';
import { useDropzone } from 'react-dropzone';
import {
  UPLOAD_ALLOWED_MIME,
  UPLOAD_MAX_BYTES,
  UPLOAD_MIN_HEIGHT,
  UPLOAD_MIN_WIDTH,
} from '@/lib/backgrounds/schemas';
import { useUploadBackgroundMutation } from '@/hooks/use-background-queries';

export function BackgroundUploadTab() {
  const [error, setError] = useState<string | null>(null);
  const upload = useUploadBackgroundMutation();

  const onDrop = async (files: File[]) => {
    setError(null);
    const file = files[0];
    if (!file) return;
    if (!(UPLOAD_ALLOWED_MIME as readonly string[]).includes(file.type)) {
      setError('JPG, PNG, or WebP only.');
      return;
    }
    if (file.size > UPLOAD_MAX_BYTES) {
      setError('File exceeds 5 MB.');
      return;
    }
    try {
      const bmp = await createImageBitmap(file);
      if (bmp.width < UPLOAD_MIN_WIDTH || bmp.height < UPLOAD_MIN_HEIGHT) {
        setError(
          `Need at least ${UPLOAD_MIN_WIDTH}x${UPLOAD_MIN_HEIGHT}; got ${bmp.width}x${bmp.height}.`,
        );
        return;
      }
    } catch {
      setError('Could not read image.');
      return;
    }
    upload.mutate(file);
  };

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    maxFiles: 1,
    multiple: false,
    accept: { 'image/jpeg': [], 'image/png': [], 'image/webp': [] },
  });

  return (
    <div className="space-y-3">
      <div
        {...getRootProps()}
        className={`rounded-xl border-2 border-dashed p-10 text-center cursor-pointer transition-colors ${
          isDragActive
            ? 'border-primary bg-primary/10'
            : 'border-outline-variant bg-white/10'
        }`}
      >
        <input {...getInputProps()} />
        <p className="font-headline-md text-headline-md">Drop an image here</p>
        <p className="text-on-surface-variant mt-2">
          JPG, PNG, or WebP &middot; at least 1280x720 &middot; max 5 MB
        </p>
      </div>
      {error && (
        <p role="alert" className="text-error">
          {error}
        </p>
      )}
      {upload.isPending && (
        <p className="text-on-surface-variant">Uploading...</p>
      )}
      {upload.isError && (
        <p role="alert" className="text-error">
          {(upload.error as Error)?.message}
        </p>
      )}
    </div>
  );
}
