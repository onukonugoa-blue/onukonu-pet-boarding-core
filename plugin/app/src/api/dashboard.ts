import { api } from './client'

export interface DashboardData {
  kpis: {
    active_stays: number
    checkins_today: number
    checkouts_today: number
    revenue_month: number
    outstanding: number
    tasks_due: number
    new_inquiries: number
  }
  todays_checkins: CheckinItem[]
  todays_checkouts: CheckoutItem[]
  open_tasks: TaskItem[]
  pet_birthdays: BirthdayItem[]
  date: string
  operational_start_date: string
}

export interface CheckinItem {
  stay_id: number
  booking_id: number
  pet_name: string
  breed?: string
  client_name: string
  client_phone: string
  check_in_date: string
  check_in_slot?: string
  kennel?: string
  status: string
}

export interface CheckoutItem {
  stay_id: number
  booking_id: number
  pet_name: string
  client_name: string
  check_out_date: string
  check_out_slot?: string
  status: string
  due?: number
  payment_status?: string
}

export interface TaskItem {
  id: number
  title: string
  priority: string
  due_date?: string
  assignee?: string
  status: string
}

export interface BirthdayItem {
  pet_name: string
  client_name: string
  age: number
}

export const dashboardApi = {
  get: (branchId?: number) => {
    const q = branchId ? `?branch_id=${branchId}` : ''
    return api.get<DashboardData>(`/dashboard${q}`)
  },
}
