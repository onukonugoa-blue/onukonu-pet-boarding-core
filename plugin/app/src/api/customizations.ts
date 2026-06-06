import { api } from './client'

export interface CustomizationItem {
  key: string
  category: 'facility' | 'legal' | 'onboarding' | 'inquiry' | 'invoice' | 'invoice_branding'
  label: string
  type: 'text' | 'textarea' | 'richtext' | 'media'
  value: string
  media_url?: string
  is_default: boolean
  updated_at: string | null
  updated_by: number | null
}

export interface PreviewResult {
  key: string
  template: string
  rendered: string
  context: Record<string, string>
  warnings: string[]
}

export interface ExportPayload {
  exported_at: string
  plugin_version: string
  settings: Array<{
    setting_key: string
    setting_value: string
    category: string
    is_default: boolean
    updated_at: string | null
    updated_by: number | null
  }>
}

export interface MediaUploadResult {
  attachment_id: number
  url: string
  key: string | null
}

export const customizationsApi = {
  getAll: () => api.get<CustomizationItem[]>('/settings/customizations'),

  update: (key: string, value: string) =>
    api.put<CustomizationItem>(`/settings/customizations/${key}`, { value }),

  preview: (key: string) =>
    api.post<PreviewResult>('/settings/customizations/preview', { key }),

  export: () => api.get<ExportPayload>('/settings/customizations/export'),

  uploadMedia: (key: string, file: File): Promise<MediaUploadResult> => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('key', key)
    return api.upload<MediaUploadResult>('/settings/customizations/upload-media', fd)
  },
}
