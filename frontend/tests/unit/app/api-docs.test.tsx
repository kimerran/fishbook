import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('swagger-ui-react', () => ({
  default: () => <div data-testid="swagger-stub" />,
}));
vi.mock('swagger-ui-react/swagger-ui.css', () => ({}));

import ApiDocsPage from '@/app/api-docs/page';

describe('/api-docs page', () => {
  it('renders the lazy swagger surface', async () => {
    render(<ApiDocsPage />);
    expect(await screen.findByTestId('swagger-stub')).toBeInTheDocument();
  });
});
