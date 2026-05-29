import { api } from './client'
import type { PaginatedResponse } from './client'
import type { Invoice, Payment, LineItem } from './bookings'

export interface InvoiceDetail extends Invoice {
  line_items: LineItem[]
  payments: Payment[]
  stays?: unknown[]
  pet_names?: string
}

export const invoicesApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Invoice>>(`/invoices${q?'?'+q:''}`)
  },
  get:    (id: number) => api.get<InvoiceDetail>(`/invoices/${id}`),
  adjust: (id: number, data: { amount: number; description: string; is_discount?: boolean }) =>
    api.put<InvoiceDetail>(`/invoices/${id}/adjust`, data),
  recordPayment: (id: number, data: { amount: number; mode: string; paid_at?: string; transaction_id?: string; notes?: string }) =>
    api.post<InvoiceDetail>(`/invoices/${id}/payments`, data),
}

export const paymentsApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string,string>).toString()
    return api.get<PaginatedResponse<Payment> & { total_amount: number }>(`/payments${q?'?'+q:''}`)
  },
  delete: (id: number) => api.delete(`/payments/${id}`),
}
