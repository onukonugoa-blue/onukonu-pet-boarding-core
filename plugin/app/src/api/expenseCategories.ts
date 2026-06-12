import { api } from './client'

export interface ExpenseCategory {
  id: number
  name: string
  is_active: number
  sort_order: number
  created_at: string
}

export const expenseCategoriesApi = {
  list: (includeArchived = false) =>
    api.get<ExpenseCategory[]>(`/expense-categories${includeArchived ? '?include_archived=1' : ''}`),

  create: (data: { name: string; sort_order?: number }) =>
    api.post<ExpenseCategory>('/expense-categories', data),

  update: (id: number, data: Partial<Pick<ExpenseCategory, 'name' | 'sort_order' | 'is_active'>>) =>
    api.put<ExpenseCategory>(`/expense-categories/${id}`, data),

  archive: (id: number) =>
    api.delete(`/expense-categories/${id}`),
}
