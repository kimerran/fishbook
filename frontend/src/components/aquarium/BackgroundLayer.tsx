'use client';

import Image from 'next/image';
import { useState, useSyncExternalStore } from 'react';
import { useActiveBackgroundQuery } from '@/hooks/use-background-queries';

const reducedMotionStore = {
  subscribe: (cb: () => void) => {
    if (typeof window === 'undefined') return () => undefined;
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    mq.addEventListener('change', cb);
    return () => mq.removeEventListener('change', cb);
  },
  getSnapshot: () =>
    typeof window === 'undefined'
      ? false
      : window.matchMedia('(prefers-reduced-motion: reduce)').matches,
  getServerSnapshot: () => false,
};

export function BackgroundLayer() {
  const active = useActiveBackgroundQuery();
  const [loaded, setLoaded] = useState(false);
  const reduced = useSyncExternalStore(
    reducedMotionStore.subscribe,
    reducedMotionStore.getSnapshot,
    reducedMotionStore.getServerSnapshot,
  );

  if (!active) {
    return (
      <div
        data-testid="bg-fallback"
        className="fixed inset-0 -z-10 pointer-events-none bg-[radial-gradient(circle_at_30%_20%,_var(--primary-container)_0%,_var(--surface)_60%)]"
        aria-hidden="true"
      />
    );
  }

  const transition = reduced ? '' : 'transition-opacity duration-700';
  const opacity = loaded || reduced ? 'opacity-100' : 'opacity-0';

  return (
    <>
      {/* Background images are R2/S3-issued signed URLs with known dimensions stored on
          the backgrounds row. next/image gives us AVIF/WebP conversion + priority
          loading. Hostname allowlist lives in next.config.ts -> images.remotePatterns. */}
      <Image
        src={active.signedUrl}
        alt=""
        fill
        priority
        sizes="100vw"
        unoptimized
        onLoad={() => setLoaded(true)}
        className={`fixed inset-0 -z-10 pointer-events-none object-cover ${transition} ${opacity}`}
      />
      <div
        className="fixed inset-0 -z-10 pointer-events-none bg-white/20 backdrop-blur-[4px]"
        aria-hidden="true"
      />
    </>
  );
}
