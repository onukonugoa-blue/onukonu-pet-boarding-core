import { useEffect } from 'react'
import { useBranchStore } from '../store/branch'
import { api } from '../api/client'
import type { Branch } from '../store/branch'

export function useBranches() {
  const { branches, setBranches, activeBranchId, setActiveBranch, activeBranch } = useBranchStore()

  useEffect(() => {
    if (branches.length === 0) {
      api.get<Branch[]>('/branches').then(setBranches).catch(console.error)
    }
  }, [])

  return { branches, activeBranchId, setActiveBranch, activeBranch: activeBranch() }
}
