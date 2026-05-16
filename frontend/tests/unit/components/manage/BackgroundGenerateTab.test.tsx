import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const mutateMock = vi.fn();
let isPending = false;

vi.mock('@/hooks/use-background-queries', () => ({
  useGenerateBackgroundMutation: () => ({
    mutate: mutateMock,
    isPending,
    isError: false,
    error: null,
  }),
}));

import { BackgroundGenerateTab } from '@/components/manage/BackgroundGenerateTab';

function renderTab() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <BackgroundGenerateTab />
    </QueryClientProvider>,
  );
}

describe('BackgroundGenerateTab', () => {
  beforeEach(() => {
    mutateMock.mockReset();
    isPending = false;
  });

  it('shows zod error for too-short prompts', async () => {
    renderTab();
    fireEvent.change(screen.getByLabelText(/prompt/i), { target: { value: 'no' } });
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/at least 3/i);
    expect(mutateMock).not.toHaveBeenCalled();
  });

  it('submits a valid input to the generate mutation', async () => {
    renderTab();
    fireEvent.change(screen.getByLabelText(/prompt/i), {
      target: { value: 'a calm coral reef at dusk' },
    });
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));
    await waitFor(() => expect(mutateMock).toHaveBeenCalledTimes(1));
    expect(mutateMock.mock.calls[0]?.[0]).toMatchObject({
      prompt: 'a calm coral reef at dusk',
      aspect_ratio: '16:9',
    });
  });

  it('shows the painting spinner copy while pending', async () => {
    isPending = true;
    renderTab();
    expect(screen.getByText(/Painting your aquarium/i)).toBeTruthy();
    expect(screen.getByText(/up to a minute/i)).toBeTruthy();
  });
});
