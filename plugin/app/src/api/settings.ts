import { api } from './client'

export interface BoardingService {
  id: number
  branch_id: number
  catalogue_name: string
  boarding_type: 'DAY' | 'OVERNIGHT'
  pet_type: 'DOG' | 'CAT' | 'ANY'
  row_type: string
  amount?: number
  discount_type?: string
  breed_size?: string
  kennel_category?: string
  meal_name?: string
  meal_type?: string
  price_type?: string
  modifies_base_bill?: number
  min_pets?: number
  days?: number
  min_age_months?: number
  max_age_months?: number
  breed?: string
  extra_info?: string
  is_active: number
  sort_order: number
}

export interface AddonService {
  id: number
  branch_id: number
  name: string
  description?: string
  service_type: 'FLAT' | 'DISTANCE_SLAB'
  base_amount: number
  visibility: 'PUBLIC' | 'PRIVATE'
  applicable_services?: string
  distance_up_to?: number
  distance_slab_amount?: number
  is_active: number
  sort_order: number
}

export interface StaffUser {
  id: number
  name: string
  email: string
  roles: string[]
  branch_id: number
}

export const settingsApi = {
  getBoardingServices: (branchId?: number) => {
    const q = branchId ? `?branch_id=${branchId}` : ''
    return api.get<BoardingService[]>(`/settings/boarding${q}`)
  },
  createBoardingService: (data: Partial<BoardingService>) => api.post<BoardingService>('/settings/boarding', data),
  updateBoardingService: (id: number, data: Partial<BoardingService>) => api.put<BoardingService>(`/settings/boarding/${id}`, data),
  deleteBoardingService: (id: number) => api.delete(`/settings/boarding/${id}`),

  getAddonServices: (branchId?: number) => {
    const q = branchId ? `?branch_id=${branchId}` : ''
    return api.get<AddonService[]>(`/settings/addons${q}`)
  },
  createAddonService: (data: Partial<AddonService>) => api.post<AddonService>('/settings/addons', data),
  updateAddonService: (id: number, data: Partial<AddonService>) => api.put<AddonService>(`/settings/addons/${id}`, data),
  deleteAddonService: (id: number) => api.delete(`/settings/addons/${id}`),

  getStaff: () => api.get<StaffUser[]>('/settings/staff'),
  updateStaff: (id: number, data: { role?: string; branch_id?: number }) => api.put<StaffUser>(`/settings/staff/${id}`, data),
}

export interface ImportColumnInfo {
  matched: string | null
  found: boolean
  searched: string[]
}

export interface ImportSkippedRow {
  row: number
  reason: string
  detail: string
}

export interface ImportDiagnostics {
  headers: {
    headers_detected: string[]
    header_count: number
    column_analysis: Record<string, ImportColumnInfo>
    missing_required: string[]
    branch_codes_in_db: string[]
  }
  skip_reasons: Record<string, number>
  skipped_rows: ImportSkippedRow[]
  note: string
}

export interface ImportResult {
  imported: number
  skipped: number
  errors: string[]
  total: number
  dry_run?: boolean
  diagnostics?: ImportDiagnostics
  error?: string
}

export interface MigrationHistoryEntry {
  entity: string
  timestamp: string
  user_id: number
  context: Record<string, unknown>
  imported: number
  skipped: number
  total: number
  errors: string[]
}

export const importApi = {
  status:  () => api.get<Record<string, number>>('/import/status'),
  history: () => api.get<MigrationHistoryEntry[]>('/import/history'),

  dryRun: (entity: string, file: File, branch?: string) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('entity', entity)
    if (branch) fd.append('branch', branch)
    return api.upload<ImportResult>('/import/dry-run', fd)
  },

  run: (entity: string, file: File, branch?: string) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('entity', entity)
    if (branch) fd.append('branch', branch)
    return api.upload<ImportResult>('/import/run', fd)
  },
}
