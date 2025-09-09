import { create } from 'zustand';

interface SelectionState {
  selectedKey: string | null;
  // Optional callback provided by the current page to remove the row locally when invalidated
  onInvalidate?: (key: string) => void;
  selectKey: (key: string, onInvalidate?: (key: string) => void) => void;
  clear: () => void;
  triggerInvalidated: (key?: string) => void;
}

export const useSelectionStore = create<SelectionState>((set, get) => ({
  selectedKey: null,
  onInvalidate: undefined,
  selectKey: (key, onInvalidate) => set({ selectedKey: key, onInvalidate }),
  clear: () => set({ selectedKey: null, onInvalidate: undefined }),
  triggerInvalidated: (key) => {
    const k = key ?? get().selectedKey;
    const cb = get().onInvalidate;
    if (k && cb) {
      try { cb(k); } catch {}
    }
    set({ selectedKey: null, onInvalidate: undefined });
  }
}));
