import { api } from './client'

export interface ReportSummary {
  total_revenue: number
  total_expenses: number
  net_profit: number
  total_bookings: number
  total_outstanding: number
}

export interface RevDay {
  day: string
  revenue: number
  expense: number
}

export interface ExpCategory {
  category: string
  total: number
}

export interface RevBranch {
  branch: string
  revenue: number
  outstanding: number
}

export interface OccWeek {
  week: string
  rate: number
  nights: number
}

export interface TopClient {
  client_id: number
  name: string
  revenue: number
  bookings: number
}

export interface ReportData {
  from: string
  to: string
  summary: ReportSummary
  revenue_by_day: RevDay[]
  expenses_by_category: ExpCategory[]
  revenue_by_branch: RevBranch[]
  occupancy_by_week: OccWeek[]
  top_clients: TopClient[]
}

export const reportsApi = {
  get: (params: { from: string; to: string; branch_id?: number }) => {
    const q = new URLSearchParams({ from: params.from, to: params.to })
    if (params.branch_id) q.set('branch_id', String(params.branch_id))
    return api.get<ReportData>(`/reports?${q}`)
  },
}
