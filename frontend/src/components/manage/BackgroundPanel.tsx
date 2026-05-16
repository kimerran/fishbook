'use client';

import * as Dialog from '@radix-ui/react-dialog';
import * as Tabs from '@radix-ui/react-tabs';
import { BackgroundGenerateTab } from './BackgroundGenerateTab';
import { BackgroundLibraryTab } from './BackgroundLibraryTab';
import { BackgroundUploadTab } from './BackgroundUploadTab';

export function BackgroundPanel({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (b: boolean) => void;
}) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-40" />
        <Dialog.Content className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-40 w-[min(95vw,768px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20">
          <Dialog.Title className="text-headline-md font-headline-md mb-4">
            Background
          </Dialog.Title>
          <Dialog.Description className="sr-only">
            Upload an image, generate one with AI, or pick from your library.
          </Dialog.Description>
          <Tabs.Root defaultValue="library">
            <Tabs.List className="flex gap-2 mb-4 font-label-caps text-[12px] tracking-[0.1em] uppercase">
              <Tabs.Trigger
                value="upload"
                className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40"
              >
                Upload
              </Tabs.Trigger>
              <Tabs.Trigger
                value="generate"
                className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40"
              >
                Generate
              </Tabs.Trigger>
              <Tabs.Trigger
                value="library"
                className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40"
              >
                Library
              </Tabs.Trigger>
            </Tabs.List>
            <Tabs.Content value="upload">
              <BackgroundUploadTab />
            </Tabs.Content>
            <Tabs.Content value="generate">
              <BackgroundGenerateTab />
            </Tabs.Content>
            <Tabs.Content value="library">
              <BackgroundLibraryTab />
            </Tabs.Content>
          </Tabs.Root>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
