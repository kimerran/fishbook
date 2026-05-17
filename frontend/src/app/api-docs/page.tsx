'use client';

import dynamic from 'next/dynamic';

import 'swagger-ui-react/swagger-ui.css';

// next/dynamic with ssr: false keeps the swagger-ui-react bundle out of every
// other route's payload. AGENT.md §6 (bundle budgets) — see specs/2026-05-17.
const SwaggerUI = dynamic(() => import('swagger-ui-react'), { ssr: false });

export default function ApiDocsPage() {
  // /api/proxy/openapi.json so localhost + prod work without host coupling.
  return <SwaggerUI url="/api/proxy/openapi.json" />;
}
