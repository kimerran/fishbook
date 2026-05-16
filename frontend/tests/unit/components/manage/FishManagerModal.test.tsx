import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, test, vi, beforeEach, afterEach } from 'vitest';
import { FishManagerModal } from '@/components/manage/FishManagerModal';

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

const fishList = {
  data: [
    {
      id: '1',
      nickname: 'Aaron',
      breed: 'guppy',
      colorHex: '#FF6B9D',
      size: 12,
      source: 'manual',
    },
  ],
  links: {},
  meta: {},
};

function makeQc() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  qc.setQueryData(['fishes', 'breeds'], breedsResponse);
  qc.setQueryData(['fishes', 'list', { search: undefined, breed: undefined, sort: 'created_at', direction: 'desc' }], fishList);
  return qc;
}

function renderModal() {
  return render(
    <QueryClientProvider client={makeQc()}>
      <FishManagerModal open={true} onOpenChange={() => {}} />
    </QueryClientProvider>,
  );
}

describe('FishManagerModal', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify(fishList), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }),
      ),
    );
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  test('renders fish rows', async () => {
    renderModal();
    await waitFor(() => {
      expect(screen.getByText('Aaron')).toBeInTheDocument();
    });
  });

  test('debounced search waits 300ms before firing a new query', async () => {
    renderModal();
    const fetchSpy = globalThis.fetch as ReturnType<typeof vi.fn>;
    const before = fetchSpy.mock.calls.length;
    fireEvent.change(screen.getByPlaceholderText(/search nickname/i), {
      target: { value: 'Aar' },
    });
    // Before debounce elapses, no new fetch.
    await act(async () => {
      vi.advanceTimersByTime(100);
    });
    expect(fetchSpy.mock.calls.length).toBe(before);
    // After debounce elapses, useFishesQuery fetches with the new search.
    await act(async () => {
      vi.advanceTimersByTime(300);
    });
    await waitFor(() => {
      const after = fetchSpy.mock.calls.length;
      expect(after).toBeGreaterThan(before);
    });
  });

  test('clicking delete calls the delete mutation', async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(JSON.stringify(fishList), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }),
      )
      .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchSpy);

    renderModal();
    await waitFor(() => expect(screen.getByText('Aaron')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    await waitFor(() => {
      const deleteCall = fetchSpy.mock.calls.find(
        (call) => (call[1] as RequestInit | undefined)?.method === 'DELETE',
      );
      expect(deleteCall).toBeDefined();
    });
  });
});
