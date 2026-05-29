import { api } from './client'
import type { Branch } from '../store/branch'

export const branchesApi = {
  list: () => api.get<Branch[]>('/branches'),
  get:  (id: number) => api.get<Branch>(`/branches/${id}`),
  update: (id: number, data: Partial<Branch>) => api.put<Branch>(`/branches/${id}`, data),
}
