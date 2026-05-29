import { api } from './client'
import type { PaginatedResponse } from './client'

export interface Client {
  id: number
  name: string
  phone: string
  email?: string
  address?: string
  local_guardian_name?: string
  local_guardian_contact?: string
  home_branch_id: number
  branch_name?: string
  branch_code?: string
  status: 'active' | 'archived'
  onboarding_date?: string
  tc_accepted: number
  wallet_balance: number
  outstanding_balance: number
  notes?: string
  pet_count?: number
  last_booking?: string
}

export interface Pet {
  id: number
  client_id: number
  name: string
  pet_type: 'Dog' | 'Cat' | 'Other'
  breed?: string
  gender?: string
  breed_size?: string
  coat?: string
  weight_kg?: number
  birthday?: string
  vaccination_status?: string
  ongoing_medication?: number
  medication_detail?: string
  is_active: number
  client_name?: string
  branch_name?: string
}

export const clientsApi = {
  list:   (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Client>>(`/clients${q?'?'+q:''}`)
  },
  get:    (id: number) => api.get<Client>(`/clients/${id}`),
  create: (data: Partial<Client>) => api.post<Client>('/clients', data),
  update: (id: number, data: Partial<Client>) => api.put<Client>(`/clients/${id}`, data),
  pets:   (id: number) => api.get<Pet[]>(`/clients/${id}/pets`),
  bookings:(id: number) => api.get<unknown[]>(`/clients/${id}/bookings`),
  createPet:(id: number, data: Partial<Pet>) => api.post<Pet>(`/clients/${id}/pets`, data),
}
