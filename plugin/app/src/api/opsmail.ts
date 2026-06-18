import { api } from './client'
import type { PaginatedResponse } from './client'

export interface OpsmailQueueEvent {
  id: number
  event_uuid: string
  event_type: string
  source_system: string
  entity_type: string
  entity_id: number
  branch_id: number | null
  origin_type: string
  priority: string
  subject: string
  summary: string | null
  recipient_email: string
  mail_status: 'PENDING' | 'SENT' | 'FAILED' | 'ACKNOWLEDGED'
  telegram_status: 'PENDING' | 'SENT' | 'FAILED'
  mail_attempts: number
  telegram_attempts: number
  last_error: string | null
  created_at: string
  sent_at: string | null
  telegram_sent_at: string | null
  branch_name: string | null
}

export interface OpsmailStats {
  by_mail_status: {
    PENDING: number
    SENT: number
    FAILED: number
    ACKNOWLEDGED: number
  }
  by_telegram_status: {
    PENDING: number
    SENT: number
    FAILED: number
  }
  total: number
  by_event: Array<{ event_type: string; cnt: number }>
  recent_failed: Array<{
    id: number
    event_type: string
    subject: string
    last_error: string | null
    created_at: string
  }>
  recent_telegram_failed: Array<{
    id: number
    event_type: string
    subject: string
    telegram_attempts: number
    last_error: string | null
    created_at: string
  }>
  opsmail_enabled: boolean
  inbox_configured: boolean
  telegram_configured: boolean
  last_telegram_sent_at: string | null
}

export interface TelegramProcessLog {
  log: Array<Record<string, unknown>>
}

export interface QueueParams {
  page?: number
  per_page?: number
  status?: string
  event_type?: string
  search?: string
}

function buildQs(params: QueueParams): string {
  const q = new URLSearchParams()
  if (params.page)       q.set('page',       String(params.page))
  if (params.per_page)   q.set('per_page',   String(params.per_page))
  if (params.status)     q.set('status',     params.status)
  if (params.event_type) q.set('event_type', params.event_type)
  if (params.search)     q.set('search',     params.search)
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const opsmailApi = {
  getQueue: (params: QueueParams = {}) =>
    api.get<PaginatedResponse<OpsmailQueueEvent>>(`/opsmail/queue${buildQs(params)}`),

  getStats: () =>
    api.get<OpsmailStats>('/opsmail/stats'),

  acknowledge: (id: number) =>
    api.post<{ id: number; mail_status: string }>(`/opsmail/queue/${id}/acknowledge`),

  processTelegram: (limit = 50) =>
    api.post<TelegramProcessLog>('/opsmail/process-telegram', { limit }),

  testTelegram: () =>
    api.post<{ ok: boolean; message: string }>('/opsmail/test-telegram'),
}
