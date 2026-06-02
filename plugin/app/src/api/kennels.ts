import { api } from './client'

export interface Kennel {
  id: number
  branch_id: number
  branch_code: string
  branch_name: string
  code: string
  name: string
  status: 'Available' | 'Occupied' | 'Maintenance' | 'Blocked'
  notes: string
  sort_order: number
  is_active: number
  assigned_staff_id?: number | null
  assigned_staff_name?: string | null
  created_at: string
  updated_at: string
}

export interface KennelOccupancy {
  kennel: Kennel
  stay: {
    id: number
    booking_id: number
    pet_name: string
    pet_type: string
    breed: string
    client_name: string
    client_phone: string
    check_in_date: string
    check_out_date: string
    status: string
  } | null
}

export interface KennelBoard {
  branch_id: number | null
  kennels: KennelOccupancy[]
  unassigned_stays: {
    id: number
    booking_id: number
    pet_name: string
    client_name: string
    check_in_date: string
    check_out_date: string
    status: string
  }[]
}

export interface KennelStaffOption {
  id: number
  name: string
}

export const kennelsApi = {
  list: (branchId?: number, activeOnly?: boolean) => {
    const params = new URLSearchParams()
    if (branchId) params.set('branch_id', String(branchId))
    if (activeOnly) params.set('active_only', '1')
    const q = params.toString() ? `?${params}` : ''
    return api.get<Kennel[]>(`/settings/kennels${q}`)
  },

  create: (data: Partial<Kennel>) =>
    api.post<Kennel>('/settings/kennels', data),

  update: (id: number, data: Partial<Kennel>) =>
    api.put<Kennel>(`/settings/kennels/${id}`, data),

  disable: (id: number) =>
    api.delete(`/settings/kennels/${id}`),

  reorder: (items: { id: number; sort_order: number }[]) =>
    api.post('/settings/kennels/reorder', { items }),

  staffOptions: () =>
    api.get<KennelStaffOption[]>('/settings/kennels/staff-options'),

  assignStaff: (kennelId: number, wpUserId: number) =>
    api.post<{ kennel_id: number; wp_user_id: number; staff_name: string }>(
      `/settings/kennels/${kennelId}/assign-staff`,
      { wp_user_id: wpUserId }
    ),

  removeStaff: (kennelId: number) =>
    api.delete<{ kennel_id: number; removed: boolean }>(
      `/settings/kennels/${kennelId}/assign-staff`
    ),
}
