import { api } from './client'

export interface InvoiceDocument {
  token: string
  url: string
  generated_at: string
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

export const invoiceDeliveryApi = {
  generateDocument: (id: number) =>
    api.post<InvoiceDocument>(`/invoices/${id}/document/generate`, {}),

  getDocument: (id: number) =>
    api.get<InvoiceDocument | null>(`/invoices/${id}/document`),

  sendEmail: (id: number, to?: string) =>
    api.post<EmailResult>(`/invoices/${id}/send-email`, { to: to ?? '' }),

  getWhatsAppLink: (id: number) =>
    api.get<WhatsAppLink>(`/invoices/${id}/whatsapp-link`),
}
