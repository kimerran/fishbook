'use client';

import { useEffect, useState } from 'react';
import { useActiveBackgroundQuery } from '@/hooks/use-background-queries';

export function BackgroundLayer() {
  const active = useActiveBackgroundQuery();
  const [loaded, setLoaded] = useState(false);
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    setReduced(mq.matches);
    const onChange = () => setReduced(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

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
      <img
        src={active.signedUrl}
        alt=""
        role="presentation"
        onLoad={() => setLoaded(true)}
        className={`fixed inset-0 -z-10 pointer-events-none w-screen h-screen object-cover ${transition} ${opacity}`}
      />
      <div
        className="fixed inset-0 -z-10 pointer-events-none bg-white/20 backdrop-blur-[4px]"
        aria-hidden="true"
      />
    </>
  );
}
