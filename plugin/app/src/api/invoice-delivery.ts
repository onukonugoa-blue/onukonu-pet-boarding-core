import { api } from './client'

export interface InvoiceDocument {
  token: string
  url: string
  pdf_url: string | null
  generated_at: string
  generated_by: number | null
}

export interface EmailResult {
  sent: boolean
  to: string
}

export interface WhatsAppLink {
  url: string
  message: string
  phone: string
}

export interface AuditEvent {
  id: number
  event: 'generated' | 'regenerated' | 'email_sent' | 'whatsapp_shared'
  performed_by: number | null
  performed_at: string
  meta: Record<string, unknown> | null
}

export const invoiceDeliveryApi = {
  generateDocument: (id: number) =>
    api.post<InvoiceDocument>(`/invoices/${id}/document/generate`, {}),

  getDocument: (id: number) =>
    api.get<InvoiceDocument | null>(`/invoices/${id}/document`),

  sendEmail: (id: number, to?: string) =>
    api.post<EmailResult>(`/invoices/${id}/send-email`, { to: to ?? '' }),

  getWhatsAppLink: (id: number) =>
    api.get<WhatsAppLink>(`/invoices/${id}/whatsapp-link`),

  getAudit: (id: number) =>
    api.get<AuditEvent[]>(`/invoices/${id}/audit`),
}
