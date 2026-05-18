import Link from "next/link";

export default function Landing() {
  return (
    <main
      className="relative min-h-screen overflow-hidden bg-cover bg-center"
      style={{ backgroundImage: "url('/landing-bg.webp')" }}
    >
      {/* Soft frost overlay so headline + buttons stay legible over any background. */}
      <div
        aria-hidden
        className="absolute inset-0 bg-white/30 backdrop-blur-[2px]"
      />

      <section
        className="relative mx-auto max-w-[1200px]
                   px-4 md:px-12
                   flex flex-col items-center justify-center min-h-screen
                   text-center gap-6"
      >
        <p className="label-caps text-on-surface-variant">Fishbook</p>
        <h1
          className="text-[24px] md:text-[32px] font-light leading-[1.2]
                     tracking-[0.02em] text-on-surface max-w-[24ch]"
        >
          Your Zen Sanctuary, Powered by Code.
        </h1>
        <p
          className="font-light leading-[1.6] text-on-surface
                     max-w-[48ch] text-[16px]
                     glass-sm rounded-2xl px-5 py-3"
        >
          A virtual aquarium for the curious. Curate a school of fish, shape the
          atmosphere, and let your favorite repository become its own tide pool.
        </p>
        <div className="flex gap-4 mt-4">
          <Link
            href="/login"
            className="glass-md rounded-full px-6 py-3 label-caps
                       text-on-surface hover:bg-white/40 transition-colors"
          >
            Sign in
          </Link>
          <Link
            href="/register"
            className="glass-sm rounded-full px-6 py-3 label-caps
                       text-on-surface-variant hover:bg-white/30 transition-colors"
          >
            Create account
          </Link>
        </div>
      </section>
    </main>
  );
}
