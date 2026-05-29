import { api } from './client'
import type { PaginatedResponse } from './client'

export interface Expense {
  id: number
  branch_id: number
  branch_name?: string
  description: string
  amount: number
  amount_inc_tax?: number
  mode: 'Cash' | 'UPI' | 'Other'
  category?: string
  expense_at: string
  notes?: string
}

export const expensesApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Expense> & { total_amount: number }>(`/expenses${q?'?'+q:''}`)
  },
  create: (data: Partial<Expense>) => api.post<Expense>('/expenses', data),
  delete: (id: number) => api.delete(`/expenses/${id}`),
}
