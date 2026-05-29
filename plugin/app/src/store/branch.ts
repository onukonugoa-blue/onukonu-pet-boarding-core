import { create } from 'zustand'

export interface Branch {
  id: number
  code: string
  name: string
  location: string
  address?: string
  phone?: string
  email?: string
  whatsapp_templates?: string
}

interface BranchStore {
  branches: Branch[]
  activeBranchId: number
  setBranches: (b: Branch[]) => void
  setActiveBranch: (id: number) => void
  activeBranch: () => Branch | undefined
}

const STORAGE_KEY = 'opb_branch_id'

const savedBranch = (): number => {
  const v = localStorage.getItem(STORAGE_KEY)
  if (v) return parseInt(v, 10)
  return window.OPB?.user?.branchId ?? 0
}

export const useBranchStore = create<BranchStore>((set, get) => ({
  branches: [],
  activeBranchId: savedBranch(),

  setBranches: (branches) => set({ branches }),

  setActiveBranch: (id) => {
    localStorage.setItem(STORAGE_KEY, String(id))
    set({ activeBranchId: id })
  },

  activeBranch: () => {
    const { branches, activeBranchId } = get()
    return branches.find((b) => b.id === activeBranchId)
  },
}))
