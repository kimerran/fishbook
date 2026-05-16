import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { RepoAquariumPage } from '@/components/repo-aquarium/RepoAquariumPage';

const stats = {
  stars: 100,
  forks: 10,
  issues: 5,
  watchers: 3,
  contributors: 2,
  language: 'Go',
  age_days: 400,
  fetched_at: '2026-05-16T00:00:00Z',
};
const fish_set = [
  {
    id: 'repo-o-r-0',
    breed: 'guppy',
    color_hex: '#FF6B9D',
    size: 12,
    nickname: 'Guppy-A4F',
    source: 'github_repo',
    source_ref: 'o/r',
  },
];

function wrap(ui: ReactNode) {
  return (
    <QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>
  );
}

describe('RepoAquariumPage', () => {
  it('renders stats panel + Fork button when authed', () => {
    render(
      wrap(
        <RepoAquariumPage
          owner="o"
          repo="r"
          stats={stats}
          fish_set={fish_set}
          isAuthed
        />,
      ),
    );
    expect(screen.getByText(/o\/r/)).toBeInTheDocument();
    expect(screen.getByText(/100/)).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: /fork to my aquarium/i }),
    ).toBeInTheDocument();
  });

  it('renders Sign-in link when unauthed', () => {
    render(
      wrap(
        <RepoAquariumPage
          owner="o"
          repo="r"
          stats={stats}
          fish_set={fish_set}
          isAuthed={false}
        />,
      ),
    );
    expect(
      screen.getByRole('link', { name: /sign in to fork/i }),
    ).toHaveAttribute('href', '/login?redirect=/o/r');
  });

  it('calls the fork mutation on click', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(
        new Response(JSON.stringify({ added: 5 }), { status: 201 }),
      );
    render(
      wrap(
        <RepoAquariumPage
          owner="o"
          repo="r"
          stats={stats}
          fish_set={fish_set}
          isAuthed
        />,
      ),
    );
    fireEvent.click(
      screen.getByRole('button', { name: /fork to my aquarium/i }),
    );
    await screen.findByText(/added 5 fish/i);
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/api/proxy/repos/o/r/fork-to-my-aquarium'),
      expect.objectContaining({ method: 'POST' }),
    );
  });
});
