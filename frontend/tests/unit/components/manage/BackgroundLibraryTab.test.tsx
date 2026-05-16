import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const selectMutate = vi.fn();
const deleteMutate = vi.fn();

vi.mock('@/hooks/use-background-queries', () => ({
  useBackgroundsQuery: () => ({
    data: {
      data: [
        {
          id: '1',
          kind: 'upload',
          storageKey: 'k/1.webp',
          signedUrl: 'https://s/1?X-Amz-Signature=a',
          width: 1280,
          height: 720,
          prompt: null,
          isActive: true,
          createdAt: new Date(),
        },
        {
          id: '2',
          kind: 'generated',
          storageKey: 'k/2.webp',
          signedUrl: 'https://s/2?X-Amz-Signature=b',
          width: 1280,
          height: 720,
          prompt: 'reef',
          isActive: false,
          createdAt: new Date(),
        },
      ],
    },
    isLoading: false,
  }),
  useSelectBackgroundMutation: () => ({ mutate: selectMutate }),
  useDeleteBackgroundMutation: () => ({ mutate: deleteMutate }),
}));

import { BackgroundLibraryTab } from '@/components/manage/BackgroundLibraryTab';

function renderTab() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <BackgroundLibraryTab />
    </QueryClientProvider>,
  );
}

describe('BackgroundLibraryTab', () => {
  beforeEach(() => {
    selectMutate.mockReset();
    deleteMutate.mockReset();
  });

  it('renders all backgrounds with the active chip on the active one', () => {
    renderTab();
    const imgs = screen.getAllByRole('presentation');
    expect(imgs.length).toBe(2);
    expect(screen.getByText(/active/i)).toBeTruthy();
  });

  it('calls select on click', () => {
    renderTab();
    const selectButtons = screen.getAllByRole('button', { name: /select background/i });
    fireEvent.click(selectButtons[1] as HTMLElement);
    expect(selectMutate).toHaveBeenCalledWith('2');
  });

  it('calls delete from the delete control', () => {
    renderTab();
    const delButtons = screen.getAllByRole('button', { name: /delete/i });
    fireEvent.click(delButtons[0] as HTMLElement);
    expect(deleteMutate).toHaveBeenCalledWith('1');
  });
});
