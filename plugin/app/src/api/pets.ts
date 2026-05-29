import { api } from './client'
import type { Pet } from './clients'

export interface PetDocument {
  id: number
  pet_id: number
  doc_type: 'photo' | 'vaccination' | 'other'
  label?: string
  file_url: string
  file_mime?: string
  seq_number: number
  created_at: string
}

export interface PetDetail extends Pet {
  vet_name?: string
  vet_contact?: string
  anti_rabies_date?: string
  dhppil_date?: string
  corona_date?: string
  kennel_cough_date?: string
  tick_prevention?: number
  last_tick_prevention_date?: string
  tick_prevention_method?: string
  deworming_date?: string
  dietary_preference?: string
  additional_meals?: string
  preferences_or_allergies?: string
  first_walk_schedule?: string
  second_walk_schedule?: string
  third_walk_schedule?: string
  neutered_or_spayed?: number
  last_heat_month?: number
  last_heat_year?: number
  consent_photos?: number
  microchip_number?: string
  adoption_status?: string
  social_media_handle?: string
  special_occasion?: string
  special_occasion_date?: string
  major_illness_history?: string
  documents?: PetDocument[]
}

export const petsApi = {
  get:    (id: number) => api.get<PetDetail>(`/pets/${id}`),
  update: (id: number, data: Partial<PetDetail>) => api.put<PetDetail>(`/pets/${id}`, data),
  documents: (id: number) => api.get<PetDocument[]>(`/pets/${id}/documents`),
  uploadDocument: (id: number, formData: FormData) => api.upload<PetDocument>(`/pets/${id}/documents`, formData),
  deleteDocument: (petId: number, docId: number) => api.delete(`/pets/${petId}/documents/${docId}`),
}
