import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, test, vi, beforeEach } from 'vitest';
import { EditFishDialog } from '@/components/manage/EditFishDialog';

const breedsResponse = {
  data: [
    {
      id: 'guppy',
      label: 'Guppy',
      minSize: 8,
      maxSize: 18,
      defaultColor: '#FF6B9D',
      spriteKey: 'guppy',
    },
  ],
};

const fishEnvelope = {
  data: {
    id: '42',
    nickname: 'Blub',
    breed: 'guppy',
    colorHex: '#FF6B9D',
    size: 12,
    source: 'manual',
  },
};

function renderDialog() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  qc.setQueryData(['fishes', 'breeds'], breedsResponse);
  qc.setQueryData(['fishes', 'one', '42'], fishEnvelope);
  return render(
    <QueryClientProvider client={qc}>
      <EditFishDialog open={true} onOpenChange={() => {}} fishId="42" />
    </QueryClientProvider>,
  );
}

const fishApiResponse = {
  data: {
    id: '42',
    nickname: 'Blub',
    breed: 'guppy',
    color_hex: '#FF6B9D',
    size: 12,
    source: 'manual',
    source_ref: null,
    created_at: '2026-05-16T00:00:00+00:00',
    updated_at: '2026-05-16T00:00:00+00:00',
  },
};

describe('EditFishDialog', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify(fishApiResponse), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }),
      ),
    );
  });

  test('renders breed as read-only chip (no card grid)', async () => {
    renderDialog();
    await waitFor(() => {
      // Breed shown as a chip, not as a button image.
      expect(screen.queryByAltText('Guppy')).toBeNull();
      expect(screen.getByText('guppy')).toBeInTheDocument();
    });
  });

  test('submit excludes breed from payload', async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ data: fishEnvelope.data }), { status: 200 }),
      );
    vi.stubGlobal('fetch', fetchSpy);

    renderDialog();
    const input = (await screen.findByRole('textbox')) as HTMLInputElement;
    await waitFor(() => expect(input.value).toBe('Blub'));
    fireEvent.change(input, { target: { value: 'Newname' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => {
      const patchCall = fetchSpy.mock.calls.find(
        (call) => (call[1] as RequestInit | undefined)?.method === 'PATCH',
      );
      expect(patchCall).toBeDefined();
    });
    const patchCall = fetchSpy.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'PATCH',
    )!;
    const body = JSON.parse((patchCall[1].body as string) ?? '{}');
    expect(body.breed).toBeUndefined();
    expect(body.nickname).toBe('Newname');
  });
});
