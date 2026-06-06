import { useEffect, useState, useCallback, useRef } from 'react'
import { Link } from 'react-router-dom'
import { customizationsApi } from '../../api/customizations'
import type { CustomizationItem, PreviewResult } from '../../api/customizations'

type Tab = 'facility' | 'legal' | 'onboarding' | 'inquiry' | 'invoice' | 'invoice_branding' | 'preview' | 'export'

const TABS: { id: Tab; label: string; icon: string }[] = [
  { id: 'facility',         label: 'Facility Info',       icon: '🏢' },
  { id: 'legal',            label: 'Legal & T&C',         icon: '📜' },
  { id: 'onboarding',       label: 'Onboarding Messages', icon: '📨' },
  { id: 'inquiry',          label: 'Inquiry Messages',    icon: '📩' },
  { id: 'invoice',          label: 'Invoice & Delivery',  icon: '🧾' },
  { id: 'invoice_branding', label: 'Invoice Branding',    icon: '🖼' },
  { id: 'preview',          label: 'Preview',             icon: '👁' },
  { id: 'export',           label: 'Export',              icon: '⬇' },
]

const VALID_PLACEHOLDERS = [
  '{{CLIENT_NAME}}', '{{FACILITY_NAME}}', '{{ONBOARDING_LINK}}',
  '{{PHONE}}', '{{EMAIL}}',
  '{{INVOICE_NUMBER}}', '{{INVOICE_LINK}}', '{{INVOICE_TOTAL}}',
  '{{INVOICE_PAID}}', '{{INVOICE_DUE}}',
]

const PLACEHOLDER_HINT: Record<string, string> = {
  facility:         '',
  legal:            '{{FACILITY_NAME}}',
  onboarding:       '{{CLIENT_NAME}} · {{FACILITY_NAME}} · {{ONBOARDING_LINK}} · {{PHONE}} · {{EMAIL}}',
  inquiry:          '{{CLIENT_NAME}} · {{FACILITY_NAME}} · {{PHONE}} · {{EMAIL}}',
  invoice:          '{{CLIENT_NAME}} · {{FACILITY_NAME}} · {{INVOICE_NUMBER}} · {{INVOICE_LINK}} · {{INVOICE_TOTAL}} · {{INVOICE_PAID}} · {{INVOICE_DUE}}',
  invoice_branding: '',
}

// Section headers shown inside the Invoice Branding tab, keyed by the first item in each group
const BRANDING_SECTION: Record<string, { title: string; desc: string }> = {
  invoice_banner_image: {
    title: 'Branding Images',
    desc: 'These images are embedded directly into every generated PDF invoice.',
  },
  invoice_upi_id: {
    title: 'Payment Details',
    desc: 'Shown in the payment section of the PDF so clients know how to pay.',
  },
  invoice_thank_you_message: {
    title: 'Messaging & Terms',
    desc: 'Footer content and terms that appear at the bottom of every invoice.',
  },
}

function canEdit(): boolean {
  const roles: string[] = window.OPB?.user?.roles ?? []
  return roles.includes('administrator') || roles.includes('opb_super_admin')
}

function validatePlaceholders(text: string): string[] {
  const matches = text.match(/\{\{[A-Z0-9_]+\}\}/g) ?? []
  return [...new Set(matches.filter((m) => !VALID_PLACEHOLDERS.includes(m)))]
}

function metaLine(item: CustomizationItem): string {
  if (item.is_default) return 'Using default value'
  const parts: string[] = []
  if (item.updated_at)
    parts.push(`Saved ${new Date(item.updated_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}`)
  return parts.join(' · ') || 'Saved'
}

// ── Media upload widget ─────────────────────────────────────────────────────────

interface MediaWidgetProps {
  settingKey: string
  label: string
  currentUrl: string
  isUploading: boolean
  readOnly: boolean
  isDefault: boolean
  onUpload: (key: string, file: File) => void
}

function MediaWidget({ settingKey, label, currentUrl, isUploading, readOnly, isDefault, onUpload }: MediaWidgetProps) {
  const inputRef = useRef<HTMLInputElement>(null)

  return (
    <div className="mb-6">
      <div className="flex items-center justify-between mb-2 gap-2">
        <label className="form-label mb-0 font-semibold">{label}</label>
        <span className="text-xs text-gray-400">{isDefault ? 'No image set' : 'Saved'}</span>
      </div>

      <div className="flex items-start gap-4">
        {currentUrl ? (
          <div className="border border-gray-200 rounded-lg overflow-hidden bg-gray-50 flex-shrink-0">
            <img
              src={currentUrl}
              alt={label}
              className="h-24 w-auto max-w-[200px] object-contain"
            />
          </div>
        ) : (
          <div className="h-24 w-36 rounded-lg border-2 border-dashed border-gray-200 bg-gray-50 flex flex-col items-center justify-center text-gray-400 text-xs gap-1 flex-shrink-0">
            <span className="text-2xl">🖼</span>
            <span>No image</span>
          </div>
        )}

        {!readOnly && (
          <div className="flex flex-col items-start gap-2 pt-1">
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              disabled={isUploading}
              className="btn-secondary text-sm disabled:opacity-50 whitespace-nowrap"
            >
              {isUploading ? (
                <span className="flex items-center gap-1.5">
                  <span className="animate-spin inline-block w-3.5 h-3.5 border-2 border-blue-500 border-t-transparent rounded-full" />
                  Uploading…
                </span>
              ) : currentUrl ? '↑ Replace Image' : '↑ Upload Image'}
            </button>

            {currentUrl && (
              <a
                href={currentUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs text-blue-600 hover:underline"
              >
                View full size ↗
              </a>
            )}

            <p className="text-xs text-gray-400 leading-snug">
              PNG, JPG, or WebP.<br />Recommended: max 1200 × 300 px.
            </p>

            <input
              ref={inputRef}
              type="file"
              accept="image/png,image/jpeg,image/webp,image/gif"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) onUpload(settingKey, f)
                e.target.value = ''
              }}
            />
          </div>
        )}

        {readOnly && !currentUrl && (
          <p className="text-sm text-gray-400 pt-1">No image uploaded.</p>
        )}
      </div>
    </div>
  )
}

// ── Standard text / textarea / richtext field ───────────────────────────────────

interface FieldProps {
  item: CustomizationItem
  value: string
  mediaUrl: string
  uploadingKey: string | null
  onChange: (key: string, val: string) => void
  onMediaUpload: (key: string, file: File) => void
  readOnly: boolean
}

function SettingField({ item, value, mediaUrl, uploadingKey, onChange, onMediaUpload, readOnly }: FieldProps) {
  if (item.type === 'media') {
    return (
      <MediaWidget
        settingKey={item.key}
        label={item.label}
        currentUrl={mediaUrl}
        isUploading={uploadingKey === item.key}
        readOnly={readOnly}
        isDefault={item.is_default}
        onUpload={onMediaUpload}
      />
    )
  }

  const invalid = item.type !== 'richtext' ? validatePlaceholders(value) : []
  const isDirty = value !== item.value

  return (
    <div className="mb-6">
      <div className="flex items-center justify-between mb-1 gap-2">
        <label className="form-label mb-0 font-semibold">{item.label}</label>
        <span className={`text-xs ${item.is_default && !isDirty ? 'text-gray-400' : 'text-blue-600'}`}>
          {isDirty ? '● unsaved' : metaLine(item)}
        </span>
      </div>

      {item.type === 'text' && (
        <input
          type="text"
          className="form-input"
          value={value}
          onChange={(e) => onChange(item.key, e.target.value)}
          disabled={readOnly}
          placeholder={item.label}
        />
      )}

      {(item.type === 'textarea' || item.type === 'richtext') && (
        <textarea
          className={`form-input font-mono text-sm ${item.type === 'richtext' ? 'min-h-[220px]' : 'min-h-[110px]'}`}
          value={value}
          onChange={(e) => onChange(item.key, e.target.value)}
          disabled={readOnly}
          placeholder={item.label}
        />
      )}

      {item.type === 'richtext' && (
        <p className="text-xs text-gray-400 mt-1">HTML is supported. Changes are rendered on public onboarding pages.</p>
      )}

      {invalid.length > 0 && (
        <div className="mt-1 text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2">
          ⚠ Unknown placeholder{invalid.length > 1 ? 's' : ''}: {invalid.join(', ')}
        </div>
      )}
    </div>
  )
}

// ── Main page ──────────────────────────────────────────────────────────────────

export default function Customization() {
  const [activeTab, setActiveTab] = useState<Tab>('facility')
  const [items, setItems] = useState<CustomizationItem[]>([])
  const [edits, setEdits] = useState<Record<string, string>>({})
  const [mediaUrls, setMediaUrls] = useState<Record<string, string>>({})
  const [uploadingKey, setUploadingKey] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [saveMsg, setSaveMsg] = useState<{ type: 'success' | 'error'; text: string } | null>(null)

  // Preview state
  const [previewKey, setPreviewKey] = useState('')
  const [previewResult, setPreviewResult] = useState<PreviewResult | null>(null)
  const [previewing, setPreviewing] = useState(false)

  // Export state
  const [exporting, setExporting] = useState(false)

  const editable = canEdit()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await customizationsApi.getAll()
      setItems(data)
      const init: Record<string, string> = {}
      const urls: Record<string, string> = {}
      data.forEach((d) => {
        init[d.key] = d.value
        if (d.type === 'media' && d.media_url) {
          urls[d.key] = d.media_url
        }
      })
      setEdits(init)
      setMediaUrls(urls)
    } catch (e: any) {
      setSaveMsg({ type: 'error', text: e.message ?? 'Failed to load settings.' })
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const handleChange = (key: string, val: string) => {
    setEdits((prev) => ({ ...prev, [key]: val }))
    setSaveMsg(null)
  }

  const handleMediaUpload = async (key: string, file: File) => {
    setSaveMsg(null)
    setUploadingKey(key)
    try {
      const result = await customizationsApi.uploadMedia(key, file)
      // Update the resolved URL for instant preview
      setMediaUrls((prev) => ({ ...prev, [key]: result.url }))
      // Update the stored value (attachment ID) and mark item as non-default
      setEdits((prev) => ({ ...prev, [key]: String(result.attachment_id) }))
      setItems((prev) =>
        prev.map((it) =>
          it.key === key ? { ...it, value: String(result.attachment_id), is_default: false, media_url: result.url } : it
        )
      )
      setSaveMsg({ type: 'success', text: `${file.name} uploaded successfully.` })
    } catch (e: any) {
      setSaveMsg({ type: 'error', text: e.message ?? 'Upload failed.' })
    } finally {
      setUploadingKey(null)
    }
  }

  const tabItems = items.filter((i) => i.category === activeTab)
  const dirtyKeys = tabItems
    .filter((i) => i.type !== 'media') // media fields save on upload, not via Save button
    .filter((i) => edits[i.key] !== undefined && edits[i.key] !== i.value)
    .map((i) => i.key)
  const hasDirty = dirtyKeys.length > 0

  const handleSave = async () => {
    if (!editable || !hasDirty) return
    setSaving(true)
    setSaveMsg(null)
    try {
      for (const key of dirtyKeys) {
        const updated = await customizationsApi.update(key, edits[key])
        setItems((prev) => prev.map((it) => (it.key === key ? updated : it)))
      }
      setSaveMsg({ type: 'success', text: `${dirtyKeys.length} setting${dirtyKeys.length > 1 ? 's' : ''} saved.` })
    } catch (e: any) {
      setSaveMsg({ type: 'error', text: e.message ?? 'Failed to save.' })
    } finally {
      setSaving(false)
    }
  }

  const handlePreview = async () => {
    if (!previewKey) return
    setPreviewing(true)
    setPreviewResult(null)
    try {
      const result = await customizationsApi.preview(previewKey)
      setPreviewResult(result)
    } catch (e: any) {
      setSaveMsg({ type: 'error', text: e.message ?? 'Preview failed.' })
    } finally {
      setPreviewing(false)
    }
  }

  const handleExport = async () => {
    setExporting(true)
    try {
      const data = await customizationsApi.export()
      const json = JSON.stringify(data, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `opb-customizations-${new Date().toISOString().slice(0, 10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e: any) {
      setSaveMsg({ type: 'error', text: e.message ?? 'Export failed.' })
    } finally {
      setExporting(false)
    }
  }

  const previewableItems = items.filter(
    (i) => i.type !== 'text' || i.category === 'onboarding' || i.category === 'inquiry'
  )

  const isBrandingTab = activeTab === 'invoice_branding'

  return (
    <div className="max-w-3xl">
      <div className="flex items-center gap-3 mb-5">
        <Link to="/settings" className="text-blue-600 hover:underline text-sm">← Settings</Link>
        <span className="text-gray-400">/</span>
        <h1 className="page-title mb-0">Customization</h1>
      </div>

      {!editable && (
        <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
          You have view-only access. Contact an administrator to make changes.
        </div>
      )}

      {/* Tab bar */}
      <div className="flex gap-1 border-b border-gray-200 mb-6 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => { setActiveTab(t.id); setSaveMsg(null) }}
            className={`flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors
              ${activeTab === t.id
                ? 'border-blue-600 text-blue-700'
                : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300'}`}
          >
            <span>{t.icon}</span>
            <span>{t.label}</span>
          </button>
        ))}
      </div>

      {/* Save feedback */}
      {saveMsg && (
        <div className={`mb-4 px-4 py-3 rounded-lg text-sm border ${
          saveMsg.type === 'success'
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-800'
        }`}>
          {saveMsg.type === 'success' ? '✓ ' : '⚠ '}{saveMsg.text}
        </div>
      )}

      {/* ── Content ─────────────────────────────────────────────────── */}

      {loading ? (
        <div className="card text-center py-12 text-gray-400">Loading customizations…</div>
      ) : activeTab === 'preview' ? (
        <PreviewTab
          items={previewableItems}
          previewKey={previewKey}
          setPreviewKey={setPreviewKey}
          result={previewResult}
          onPreview={handlePreview}
          previewing={previewing}
        />
      ) : activeTab === 'export' ? (
        <ExportTab onExport={handleExport} exporting={exporting} />
      ) : (
        <>
          {/* Invoice branding intro banner */}
          {isBrandingTab && (
            <div className="mb-4 flex items-start gap-3 px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-800">
              <span className="text-lg leading-none mt-0.5">🖼</span>
              <div>
                <p className="font-semibold mb-0.5">Invoice Branding Settings</p>
                <p className="text-indigo-700 text-xs leading-relaxed">
                  Images and text here appear on every generated PDF invoice. Upload images directly — they save instantly without needing the Save button.
                </p>
              </div>
            </div>
          )}

          {/* Placeholder hint for non-branding text tabs */}
          {!isBrandingTab && PLACEHOLDER_HINT[activeTab] && (
            <div className="mb-4 px-3 py-2.5 bg-blue-50 border border-blue-100 rounded-lg text-xs text-blue-700">
              <span className="font-semibold">Supported placeholders:</span>{' '}
              {PLACEHOLDER_HINT[activeTab].split(' · ').map((p) => (
                <code key={p} className="mx-0.5 bg-blue-100 px-1 rounded font-mono">{p}</code>
              ))}
            </div>
          )}

          {activeTab === 'legal' && (
            <div className="mb-4 px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-600">
              <span className="font-semibold">T&C versioning:</span> Update <code className="bg-gray-100 px-1 rounded font-mono">T&C Version</code> whenever you make substantive changes to the terms. The version is recorded when customers accept the terms during onboarding.
            </div>
          )}

          {/* Items */}
          <div className="card">
            {isBrandingTab ? (
              // Invoice branding: render with section headers
              <>
                {tabItems.map((item) => (
                  <div key={item.key}>
                    {BRANDING_SECTION[item.key] && (
                      <div className="mb-5 mt-2 first:mt-0">
                        {/* Close previous section gap */}
                        {item.key !== tabItems[0]?.key && (
                          <hr className="border-gray-100 mb-5 -mx-6" />
                        )}
                        <h3 className="text-xs font-bold uppercase tracking-widest text-gray-500 mb-1">
                          {BRANDING_SECTION[item.key].title}
                        </h3>
                        <p className="text-xs text-gray-400 mb-4">
                          {BRANDING_SECTION[item.key].desc}
                        </p>
                      </div>
                    )}
                    <SettingField
                      item={item}
                      value={edits[item.key] ?? item.value}
                      mediaUrl={mediaUrls[item.key] ?? ''}
                      uploadingKey={uploadingKey}
                      onChange={handleChange}
                      onMediaUpload={handleMediaUpload}
                      readOnly={!editable}
                    />
                  </div>
                ))}
              </>
            ) : (
              // All other tabs: render fields directly
              tabItems.map((item) => (
                <SettingField
                  key={item.key}
                  item={item}
                  value={edits[item.key] ?? item.value}
                  mediaUrl={mediaUrls[item.key] ?? ''}
                  uploadingKey={uploadingKey}
                  onChange={handleChange}
                  onMediaUpload={handleMediaUpload}
                  readOnly={!editable}
                />
              ))
            )}
          </div>

          {/* Save bar — not shown for branding tab (media saves on upload; no text fields need batching) */}
          {editable && !isBrandingTab && (
            <div className="flex items-center justify-between mt-4">
              <span className="text-sm text-gray-400">
                {hasDirty ? `${dirtyKeys.length} unsaved change${dirtyKeys.length > 1 ? 's' : ''}` : 'All changes saved'}
              </span>
              <button
                onClick={handleSave}
                disabled={saving || !hasDirty}
                className="btn-primary disabled:opacity-50"
              >
                {saving ? 'Saving…' : 'Save Section'}
              </button>
            </div>
          )}

          {/* For branding tab: show save button only if there are dirty text fields */}
          {editable && isBrandingTab && hasDirty && (
            <div className="flex items-center justify-between mt-4">
              <span className="text-sm text-gray-400">{dirtyKeys.length} unsaved text change{dirtyKeys.length > 1 ? 's' : ''}</span>
              <button
                onClick={handleSave}
                disabled={saving}
                className="btn-primary disabled:opacity-50"
              >
                {saving ? 'Saving…' : 'Save Changes'}
              </button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ── Preview tab ─────────────────────────────────────────────────────────────────

interface PreviewTabProps {
  items: CustomizationItem[]
  previewKey: string
  setPreviewKey: (k: string) => void
  result: PreviewResult | null
  onPreview: () => void
  previewing: boolean
}

function PreviewTab({ items, previewKey, setPreviewKey, result, onPreview, previewing }: PreviewTabProps) {
  const templateItems = items.filter((i) =>
    ['textarea', 'richtext'].includes(i.type) ||
    (i.type === 'text' && (i.key.includes('subject') || i.key.includes('message')))
  )

  return (
    <div className="space-y-5">
      <div className="card">
        <h2 className="font-semibold text-gray-900 mb-4">Preview Template</h2>
        <p className="text-sm text-gray-500 mb-4">
          Select a template to see how it renders with sample data. This uses the same renderer as production.
        </p>

        <div className="flex gap-3">
          <select
            className="form-select flex-1"
            value={previewKey}
            onChange={(e) => setPreviewKey(e.target.value)}
          >
            <option value="">— Select a template —</option>
            {templateItems.map((i) => (
              <option key={i.key} value={i.key}>[{i.category}] {i.label}</option>
            ))}
          </select>
          <button
            onClick={onPreview}
            disabled={!previewKey || previewing}
            className="btn-primary whitespace-nowrap disabled:opacity-50"
          >
            {previewing ? 'Previewing…' : 'Preview'}
          </button>
        </div>
      </div>

      {result && (
        <>
          {result.warnings.length > 0 && (
            <div className="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800 space-y-1">
              <p className="font-semibold">⚠ Placeholder warnings</p>
              {result.warnings.map((w, i) => <p key={i}>{w}</p>)}
            </div>
          )}

          <div className="card">
            <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Rendered Output</h3>
            <div
              className="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm whitespace-pre-wrap font-mono leading-relaxed text-gray-800"
              style={{ maxHeight: 320, overflowY: 'auto' }}
            >
              {result.rendered}
            </div>
          </div>

          <div className="card">
            <h3 className="font-semibold text-gray-800 mb-3 text-sm uppercase tracking-wide">Sample Values Used</h3>
            <table className="data-table">
              <thead>
                <tr><th>Placeholder</th><th>Sample Value</th></tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {Object.entries(result.context).map(([k, v]) => (
                  <tr key={k}>
                    <td><code className="text-xs bg-gray-100 px-1.5 py-0.5 rounded font-mono">{`{{${k}}}`}</code></td>
                    <td className="text-gray-600 text-sm">{v}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}

// ── Export tab ──────────────────────────────────────────────────────────────────

function ExportTab({ onExport, exporting }: { onExport: () => void; exporting: boolean }) {
  return (
    <div className="card space-y-4">
      <h2 className="font-semibold text-gray-900">Export Configuration</h2>
      <p className="text-sm text-gray-600">
        Download all customization settings as a JSON file. The export includes every setting key, its current value, category, and last-modified metadata.
      </p>
      <p className="text-sm text-gray-600">
        Use this for backup, recovery, or migration between environments. Import is not yet available — use this file as a reference to restore settings manually if needed.
      </p>
      <div className="pt-2">
        <button
          onClick={onExport}
          disabled={exporting}
          className="btn-primary disabled:opacity-50"
        >
          {exporting ? 'Preparing…' : '⬇ Download JSON Export'}
        </button>
      </div>
      <p className="text-xs text-gray-400">
        Exported file includes: setting_key · setting_value · category · is_default · updated_at · updated_by
      </p>
    </div>
  )
}
