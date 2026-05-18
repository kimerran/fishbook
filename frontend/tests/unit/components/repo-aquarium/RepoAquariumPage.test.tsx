import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
  // BackgroundLayer mounts when isAuthed and fires a real fetch on render. In CI
  // there's no server at localhost:3000, so the unhandled rejection bubbles up and
  // fails the suite even when assertions pass. Stub fetch globally; the fork test
  // overrides per-call via spyOn.
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(JSON.stringify({ data: [] }), { status: 200 }),
        ),
      ),
    );
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

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
    expect(screen.getByText(/how your aquarium is built/i)).toBeInTheDocument();
    expect(screen.getByText(/zebra danios/i)).toBeInTheDocument();
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
    // Mint a fresh Response per call: BackgroundLayer (rendered when isAuthed) also
    // fetches /api/proxy/backgrounds on mount, and Response bodies are single-shot
    // streams — sharing one would have its body already consumed before the fork POST.
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockImplementation((input) => {
        const url = typeof input === 'string' ? input : (input as Request).url;
        if (url.includes('/fork-to-my-aquarium')) {
          return Promise.resolve(
            new Response(JSON.stringify({ added: 5 }), { status: 201 }),
          );
        }
        return Promise.resolve(
          new Response(JSON.stringify({ data: [] }), { status: 200 }),
        );
      });
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
