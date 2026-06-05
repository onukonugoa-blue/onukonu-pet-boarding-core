import { create } from 'zustand'
import { customizationsApi } from '../api/customizations'
import type { CustomizationItem } from '../api/customizations'

interface CustomizationsState {
  items: Record<string, CustomizationItem>
  loaded: boolean
  loading: boolean
  fetch: () => Promise<void>
  get: (key: string) => string
}

export const useCustomizationsStore = create<CustomizationsState>((set, get) => ({
  items: {},
  loaded: false,
  loading: false,

  fetch: async () => {
    if (get().loaded || get().loading) return
    set({ loading: true })
    try {
      const data = await customizationsApi.getAll()
      const map: Record<string, CustomizationItem> = {}
      for (const item of data) {
        map[item.key] = item
      }
      set({ items: map, loaded: true, loading: false })
    } catch {
      set({ loading: false })
    }
  },

  get: (key: string) => get().items[key]?.value ?? '',
}))
