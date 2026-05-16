import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// react-dropzone mock: capture onDrop and surface a hook the test can drive.
let capturedOnDrop: ((files: File[]) => void) | null = null;
vi.mock('react-dropzone', () => ({
  useDropzone: (opts: { onDrop: (files: File[]) => void }) => {
    capturedOnDrop = opts.onDrop;
    return {
      getRootProps: () => ({}),
      getInputProps: () => ({}),
      isDragActive: false,
    };
  },
}));

// Mutation mock
const mutateMock = vi.fn();
vi.mock('@/hooks/use-background-queries', () => ({
  useUploadBackgroundMutation: () => ({
    mutate: mutateMock,
    isPending: false,
    isError: false,
    error: null,
  }),
}));

import { BackgroundUploadTab } from '@/components/manage/BackgroundUploadTab';

function makeFile(name: string, type: string, sizeBytes = 1024): File {
  const blob = new Blob([new Uint8Array(sizeBytes)], { type });
  return new File([blob], name, { type });
}

function renderTab() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <BackgroundUploadTab />
    </QueryClientProvider>,
  );
}

describe('BackgroundUploadTab', () => {
  beforeEach(() => {
    capturedOnDrop = null;
    mutateMock.mockReset();
  });

  it('rejects non-image MIME types with an inline error', async () => {
    renderTab();
    await capturedOnDrop!([makeFile('a.gif', 'image/gif')]);
    expect(await screen.findByRole('alert')).toHaveTextContent(/JPG, PNG, or WebP/);
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it('rejects files larger than 5 MB', async () => {
    renderTab();
    await capturedOnDrop!([makeFile('a.jpg', 'image/jpeg', 6 * 1024 * 1024)]);
    expect(await screen.findByRole('alert')).toHaveTextContent(/5 MB/);
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it('rejects images smaller than 1280x720', async () => {
    vi.stubGlobal(
      'createImageBitmap',
      vi.fn().mockResolvedValue({ width: 800, height: 600 } as unknown as ImageBitmap),
    );
    renderTab();
    await capturedOnDrop!([makeFile('a.jpg', 'image/jpeg')]);
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeNull());
    expect(screen.getByRole('alert').textContent).toMatch(/1280/);
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it('calls the upload mutation with a valid file', async () => {
    vi.stubGlobal(
      'createImageBitmap',
      vi.fn().mockResolvedValue({ width: 1280, height: 720 } as unknown as ImageBitmap),
    );
    renderTab();
    const file = makeFile('a.jpg', 'image/jpeg');
    await capturedOnDrop!([file]);
    await waitFor(() => expect(mutateMock).toHaveBeenCalledWith(file));
  });
});
