import { api } from './client'

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
  recorded_by?: number
  recorded_by_name?: string
  notes?: string
  created_at?: string
}

export interface ExpensesResponse {
  data: Expense[]
  total: number
  total_amount: number
  top_category: string
  date_from: string
  date_to: string
  page: number
  per_page: number
  total_pages: number
}

export const expensesApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string, string>).toString()
    return api.get<ExpensesResponse>(`/expenses${q ? '?' + q : ''}`)
  },
  categories: (branchId?: number) => {
    const q = branchId ? `?branch_id=${branchId}` : ''
    return api.get<string[]>(`/expenses/categories${q}`)
  },
  create: (data: Partial<Expense>) => api.post<Expense>('/expenses', data),
  delete: (id: number) => api.delete(`/expenses/${id}`),
}
