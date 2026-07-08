import { api } from './client'
import type { PaginatedResponse } from './client'

export interface BookingStay {
  id: number
  booking_id: number
  branch_id?: number
  pet_id: number
  pet_name?: string
  breed?: string
  breed_size?: string
  pet_type?: string
  boarding_service_id?: number
  status: 'Upcoming' | 'Active' | 'Completed' | 'No show'
  boarding_type: 'DAY' | 'OVERNIGHT'
  check_in_date: string
  check_out_date: string
  actual_check_in_at?: string
  actual_check_out_at?: string
  check_in_slot?: string
  check_out_slot?: string
  weight_at_checkin?: number
  weight_at_checkout?: number
  meal_type?: string
  kennel?: string
  kennel_id?: number
  client_name?: string
  client_phone?: string
  final_amount?: number
  late_checkout_fees?: number
  companion_name?: string
  companion_phone?: string
  notes?: string
}

export interface BookingAddon {
  id: number
  booking_id: number
  addon_id: number
  name?: string
  count: number
  unit_price?: number
  final_amount?: number
  notes?: string
}

export interface Booking {
  id: number
  branch_id: number
  branch_name?: string
  branch_code?: string
  client_id: number
  client_name?: string
  client_phone?: string
  client_email?: string
  booking_date: string
  /** Booking-level lifecycle status: Active (default) or Cancelled. */
  status?: 'Active' | 'Cancelled'
  payment_status: string
  total_billing_amount: number
  notes?: string
  additional_instruction?: string
  booking_source?: string
  pet_names?: string
  check_in_date?: string
  check_out_date?: string
  stay_status?: string
  stays?: BookingStay[]
  addons?: BookingAddon[]
  invoice?: Invoice | null
}

export interface Invoice {
  id: number
  booking_id: number
  branch_id: number
  invoice_type: string
  invoice_date: string
  revenue: number
  base_amount: number
  addon_amount: number
  discount_amount: number
  additional_amount: number
  paid: number
  due: number
  payment_status: string
  legacy_invoice_number?: string
  client_name?: string
  client_phone?: string
  branch_name?: string
  booking_date?: string
  line_items?: LineItem[]
  payments?: Payment[]
}

export interface LineItem {
  id: number
  invoice_id: number
  bill_section: 'Base' | 'Add-on' | 'Discount' | 'Additional'
  bill_item_name: string
  quantity: number
  amount: number
  subtotal: number
  total: number
  is_return: number
}

export interface Payment {
  id: number
  invoice_id: number
  branch_id: number
  amount: number
  mode: string
  paid_at: string
  transaction_id?: string
  notes?: string
}

export interface KennelBoard {
  days: string[]
  stays: BookingStay[]
  from: string
  to: string
}

export const bookingsApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Booking>>(`/bookings${q?'?'+q:''}`)
  },
  get:    (id: number) => api.get<Booking>(`/bookings/${id}`),
  create: (data: unknown) => api.post<Booking>('/bookings', data),
  update: (id: number, data: unknown) => api.put<Booking>(`/bookings/${id}`, data),
  checkin:  (id: number, data: unknown) => api.post<Booking>(`/bookings/${id}/checkin`, data),
  checkout: (id: number, data: unknown) => api.post<Booking>(`/bookings/${id}/checkout`, data),
  addAddon:    (id: number, data: unknown) => api.post<Booking>(`/bookings/${id}/addons`, data),
  removeAddon: (id: number, data: unknown) => api.delete(`/bookings/${id}/addons`),
  kennelBoard: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<KennelBoard>(`/kennel-board${q?'?'+q:''}`)
  },
  assignKennel: (stayId: number, kennelId: number | null) =>
    api.post<{ stay_id: number; kennel_id: number | null; kennel: string | null }>(
      `/stays/${stayId}/assign-kennel`,
      { kennel_id: kennelId }
    ),
  /** Cancel a booking — sets bk.status = 'Cancelled'. Requires opb_manage_bookings or higher. */
  cancel:  (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/bookings/${id}/cancel`, {}),
  /** Restore a cancelled booking to Active. Requires opb_manage_bookings or higher. */
  restore: (id: number) =>
    api.put<{ id: number; status: string }>(`/admin/bookings/${id}/restore`, {}),
}
