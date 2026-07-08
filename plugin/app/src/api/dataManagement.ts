import { api } from './client'
import type { PaginatedResponse } from './client'

export type DmView = 'active' | 'archived' | 'all'

export interface DmClient {
  id: number
  name: string
  phone: string
  email: string | null
  status: 'active' | 'archived'
  archive_reason: string | null
  branch_name: string | null
  pet_count: string
  booking_count: string
  created_at: string
}

export interface DmPet {
  id: number
  name: string
  pet_type: string
  breed: string | null
  is_active: string
  client_id: number
  client_name: string
  client_phone: string
  stay_count: string
  created_at: string
}

export interface DmBooking {
  id: number
  booking_date: string
  status: string
  payment_status: string
  total_billing_amount: string
  service_types: string | null
  client_id: number
  client_name: string
  client_phone: string
  branch_name: string | null
  pet_names: string | null
  /** Latest check-out date across all stays — used to detect future active bookings. */
  check_out_date?: string | null
}

export interface DmInquiry {
  id: number
  owner_name: string
  phone: string
  email: string | null
  pet_name: string | null
  pet_type: string | null
  status: string
  branch_name: string | null
  created_at: string
}

function toQs(params: Record<string, unknown>): string {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') p.set(k, String(v))
  }
  return p.toString()
}

export const dataManagementApi = {
  // Clients
  listClients: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<DmClient>>(`/admin/clients?${toQs(params)}`),
  archiveClient: (id: number, reason: string) =>
    api.put<{ id: number; status: string }>(`/admin/clients/${id}/archive`, { reason }),
  restoreClient: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/clients/${id}/restore`, {}),

  // Pets
  listPets: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<DmPet>>(`/admin/pets?${toQs(params)}`),
  archivePet: (id: number) =>
    api.put<{ id: number; is_active: boolean }>(`/admin/pets/${id}/archive`, {}),
  restorePet: (id: number) =>
    api.put<{ id: number; is_active: boolean }>(`/admin/pets/${id}/restore`, {}),

  // Bookings
  listBookings: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<DmBooking>>(`/admin/bookings?${toQs(params)}`),
  cancelBooking: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/bookings/${id}/cancel`, {}),
  restoreBooking: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/bookings/${id}/restore`, {}),

  // Inquiries
  listInquiries: (params: Record<string, unknown>) =>
    api.get<PaginatedResponse<DmInquiry>>(`/admin/inquiries?${toQs(params)}`),
  archiveInquiry: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/inquiries/${id}/archive`, {}),
  restoreInquiry: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/inquiries/${id}/restore`, {}),
}
