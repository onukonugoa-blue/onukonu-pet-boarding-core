declare global {
  interface Window {
    OPB: {
      apiBase: string
      nonce: string
      adminUrl: string
      user: { id: number; name: string; roles: string[]; branchId: number }
    }
  }
}

const getBase = () => window.OPB?.apiBase ?? '/wp-json/opb/v1'
const getNonce = () => window.OPB?.nonce ?? ''

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
  }
}

async function request<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const url = `${getBase()}${path}`
  const res = await fetch(url, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': getNonce(),
      ...(options.headers ?? {}),
    },
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({ message: res.statusText }))
    throw new ApiError(res.status, body?.message ?? res.statusText)
  }
  return res.json()
}

export const api = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  post: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'POST', body: JSON.stringify(data) }),
  put: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(data) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),

  upload: async <T>(path: string, formData: FormData): Promise<T> => {
    const res = await fetch(`${getBase()}${path}`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': getNonce() },
      body: formData,
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({ message: res.statusText }))
      throw new ApiError(res.status, body?.message ?? res.statusText)
    }
    return res.json()
  },
}

export type PaginatedResponse<T> = {
  data: T[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export const fmt = {
  inr: (n: number | string) =>
    `₹${Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`,
  date: (d: string | null) =>
    d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—',
  datetime: (d: string | null) =>
    d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—',
}

export function normalisePhone(phone: string): string {
  const digits = phone.replace(/\D/g, '')
  if (digits.startsWith('0')) return '91' + digits.slice(1)
  if (digits.length === 10) return '91' + digits
  return digits.replace(/^\+/, '')
}

export function buildWhatsAppUrl(phone: string, message: string): string {
  const num = normalisePhone(phone)
  return `https://wa.me/${num}?text=${encodeURIComponent(message)}`
}
