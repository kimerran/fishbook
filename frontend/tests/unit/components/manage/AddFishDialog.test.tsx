import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, test, vi, beforeEach } from 'vitest';
import { AddFishDialog } from '@/components/manage/AddFishDialog';

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
    {
      id: 'molly',
      label: 'Molly',
      minSize: 12,
      maxSize: 22,
      defaultColor: '#1F2937',
      spriteKey: 'molly',
    },
  ],
};

function renderDialog() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  qc.setQueryData(['fishes', 'breeds'], breedsResponse);
  return render(
    <QueryClientProvider client={qc}>
      <AddFishDialog open={true} onOpenChange={() => {}} />
    </QueryClientProvider>,
  );
}

describe('AddFishDialog', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 201 })));
  });

  test('renders breed grid with sprites', async () => {
    renderDialog();
    await waitFor(() => {
      expect(screen.getByAltText('Guppy')).toBeInTheDocument();
      expect(screen.getByAltText('Molly')).toBeInTheDocument();
    });
  });

  test('submit calls fetch with normalized payload', async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ data: { id: '1' } }), {
          status: 201,
          headers: { 'content-type': 'application/json' },
        }),
      );
    vi.stubGlobal('fetch', fetchSpy);

    renderDialog();
    const input = await screen.findByRole('textbox');
    fireEvent.change(input, { target: { value: 'Blub' } });
    fireEvent.click(screen.getByRole('button', { name: /add fish/i }));

    await waitFor(() => {
      const postCall = fetchSpy.mock.calls.find(
        (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
      );
      expect(postCall).toBeDefined();
    });
    const postCall = fetchSpy.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    )!;
    const body = JSON.parse((postCall[1].body as string) ?? '{}');
    expect(body.nickname).toBe('Blub');
    expect(body.color_hex).toMatch(/^#[0-9A-Fa-f]{6}$/);
  });
});
