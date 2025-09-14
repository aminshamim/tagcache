import { create } from 'zustand';

interface CacheStore {
  flushCounter: number;
  triggerFlush: () => void;
}

export const useCacheStore = create<CacheStore>((set) => ({
  flushCounter: 0,
  triggerFlush: () => set((state) => ({ flushCounter: state.flushCounter + 1 })),
}));
