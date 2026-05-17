import { withSentryConfig } from '@sentry/nextjs';
import type { NextConfig } from 'next';

const baseConfig: NextConfig = {
  images: {
    remotePatterns: [
      { protocol: 'https', hostname: '*.r2.cloudflarestorage.com' },
      { protocol: 'https', hostname: '*.s3.amazonaws.com' },
      { protocol: 'http', hostname: 'localhost', port: '9000' },
    ],
  },
};

const finalConfig: NextConfig = process.env.NEXT_PUBLIC_SENTRY_DSN
  ? withSentryConfig(baseConfig, { silent: !process.env.CI })
  : baseConfig;

export default finalConfig;
