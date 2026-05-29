import { api } from './client'
import type { PaginatedResponse } from './client'

export interface Task {
  id: number
  branch_id: number
  branch_name?: string
  client_id?: number
  client_name?: string
  title: string
  description?: string
  status: 'Open' | 'In Progress' | 'Done'
  priority: 'Low' | 'Medium' | 'High'
  due_date?: string
  assignee?: string
  assigned_by?: string
  comments?: string
  created_at: string
  updated_at: string
}

export const tasksApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Task>>(`/tasks${q?'?'+q:''}`)
  },
  get:    (id: number) => api.get<Task>(`/tasks/${id}`),
  create: (data: Partial<Task>) => api.post<Task>('/tasks', data),
  update: (id: number, data: Partial<Task>) => api.put<Task>(`/tasks/${id}`, data),
  delete: (id: number) => api.delete(`/tasks/${id}`),
}
