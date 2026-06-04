import { api } from './client'
import type { PaginatedResponse } from './client'

export interface Inquiry {
  id: number
  token: string
  owner_name: string
  phone: string
  email?: string
  pet_name?: string
  pet_type?: string
  desired_check_in?: string
  desired_check_out?: string
  message?: string
  status: InquiryStatus
  branch_id?: number
  branch_name?: string
  existing_client_id?: number
  onboarding_sent_at?: string
  onboarding_sent_by?: number
  delivery_method?: 'EMAIL' | 'WHATSAPP' | 'MANUAL'
  token_expires_at?: string
  token_send_count?: number
  converted_client_id?: number
  converted_at?: string
  converted_by?: number
  ip_address?: string
  source?: string
  created_at: string
  updated_at: string
  note_count?: number
  doc_count?: number
  onboarding_url?: string
}

export interface LinkLogEntry {
  id: number
  event_type: 'SENT' | 'OPENED' | 'ROTATED'
  token_suffix: string
  actor_name?: string
  notes?: string
  created_at: string
}

export type InquiryStatus =
  | 'NEW'
  | 'CONTACTED'
  | 'ONBOARDING_SENT'
  | 'ONBOARDING_COMPLETED'
  | 'READY_FOR_REVIEW'
  | 'CONVERTED'
  | 'REJECTED'
  | 'ARCHIVED'

export interface InquiryNote {
  id: number
  inquiry_id: number
  note: string
  created_by: number
  created_by_name: string
  created_at: string
}

export interface OnboardingClient {
  id: number
  inquiry_id: number
  name?: string
  phone?: string
  email?: string
  address?: string
  local_guardian_name?: string
  local_guardian_contact?: string
  emergency_contact_name?: string
  emergency_contact_phone?: string
  notes?: string
  tc_accepted: number
  tc_accepted_at?: string
  tc_version?: string
  tc_ip?: string
  completed_at?: string
}

export interface OnboardingPet {
  id: number
  inquiry_id: number
  name?: string
  pet_type?: string
  breed?: string
  gender?: string
  breed_size?: string
  weight_kg?: number
  birthday?: string
  vaccination_status?: string
  anti_rabies_date?: string
  dhppil_date?: string
  kennel_cough_date?: string
  vet_name?: string
  vet_contact?: string
  ongoing_medication?: number
  medication_detail?: string
  dietary_preference?: string
  preferences_or_allergies?: string
  additional_notes?: string
}

export interface OnboardingDocument {
  id: number
  inquiry_id: number
  onboarding_pet_id?: number
  doc_type: string
  label?: string
  file_url: string
  file_mime?: string
  uploaded_at: string
}

export interface ExistingClient {
  id: number
  name: string
  phone: string
  email?: string
  branch_name?: string
  pet_count: number
  booking_count: number
  match_type: 'phone' | 'email'
}

export interface InquiryDetail {
  inquiry: Inquiry
  notes: InquiryNote[]
  onboarding_client: OnboardingClient | null
  onboarding_pets: OnboardingPet[]
  documents: OnboardingDocument[]
  existing_client: ExistingClient | null
  link_log: LinkLogEntry[]
}

export interface SendOnboardingResult {
  onboarding_url: string
  whatsapp_url: string | null
  delivery_method: string
  sent_at: string
}

export interface DuplicateCheckResult {
  duplicate_found: boolean
  client: ExistingClient | null
}

export interface ConvertResult {
  client_id: number
  pet_ids: number[]
  doc_ids: number[]
  message: string
}

export const inquiriesApi = {
  list: (params: Record<string, unknown> = {}) => {
    const q = new URLSearchParams(params as Record<string, string>).toString()
    return api.get<PaginatedResponse<Inquiry>>(`/inquiries${q ? '?' + q : ''}`)
  },

  get: (id: number) => api.get<InquiryDetail>(`/inquiries/${id}`),

  updateStatus: (id: number, status: string) =>
    api.put<InquiryDetail>(`/inquiries/${id}`, { status }),

  addNote: (id: number, note: string) =>
    api.post<InquiryNote>(`/inquiries/${id}/notes`, { note }),

  sendOnboarding: (id: number, deliveryMethod: 'EMAIL' | 'WHATSAPP' | 'MANUAL') =>
    api.post<SendOnboardingResult>(`/inquiries/${id}/send-onboarding`, { delivery_method: deliveryMethod }),

  reject: (id: number, reason?: string) =>
    api.post<{ status: string }>(`/inquiries/${id}/reject`, { reason }),

  archive: (id: number) =>
    api.post<{ status: string }>(`/inquiries/${id}/archive`),

  duplicateCheck: (id: number) =>
    api.get<DuplicateCheckResult>(`/inquiries/${id}/duplicate-check`),

  convert: (id: number, branchId: number) =>
    api.post<ConvertResult>(`/inquiries/${id}/convert`, { branch_id: branchId }),

  resendOnboarding: (id: number) =>
    api.post<{ onboarding_url: string; whatsapp_url: string; resent_at: string }>(
      `/inquiries/${id}/resend-onboarding`
    ),
}

export const STATUS_LABELS: Record<InquiryStatus, string> = {
  NEW:                  'New',
  CONTACTED:            'Contacted',
  ONBOARDING_SENT:      'Onboarding Sent',
  ONBOARDING_COMPLETED: 'Onboarding Completed',
  READY_FOR_REVIEW:     'Ready for Review',
  CONVERTED:            'Converted',
  REJECTED:             'Rejected',
  ARCHIVED:             'Archived',
}

export const STATUS_COLORS: Record<InquiryStatus, string> = {
  NEW:                  'bg-blue-100 text-blue-800',
  CONTACTED:            'bg-yellow-100 text-yellow-800',
  ONBOARDING_SENT:      'bg-purple-100 text-purple-800',
  ONBOARDING_COMPLETED: 'bg-indigo-100 text-indigo-800',
  READY_FOR_REVIEW:     'bg-orange-100 text-orange-800',
  CONVERTED:            'bg-green-100 text-green-800',
  REJECTED:             'bg-red-100 text-red-800',
  ARCHIVED:             'bg-gray-100 text-gray-500',
}
