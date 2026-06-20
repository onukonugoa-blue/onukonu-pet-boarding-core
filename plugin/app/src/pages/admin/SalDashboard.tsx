import { useState, useEffect, useCallback } from 'react'

const API = (window as any).OPB?.apiBase ?? '/wp-json/opb/v1'
const nonce = (window as any).OPB?.nonce ?? ''

const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }

async function apiFetch(path: string, opts?: RequestInit) {
  const res = await fetch(API + path, { headers, ...opts })
  const json = await res.json()
  if (!res.ok) throw new Error(json?.message ?? `HTTP ${res.status}`)
  return json
}

type BriefType = 'morning' | 'evening' | 'accounts'

interface Config {
  sal_enabled: boolean
  sal_morning_brief_enabled: boolean
  sal_morning_brief_time: string
  sal_evening_brief_enabled: boolean
  sal_evening_brief_time: string
  sal_accounts_snapshot_enabled: boolean
  sal_accounts_snapshot_time: string
  sal_telegram_chat_id: string
  sal_telegram_configured: boolean
  sal_fallback_chat_id: string
  next_scheduled: Record<BriefType, string | null>
}

interface DiagEntry {
  last_run: string | null
  last_success: string | null
  last_failure: string | null
  last_error: string | null
  next_run: string | null
}

interface Diagnostics {
  diagnostics: Record<BriefType, DiagEntry>
  cron_active: boolean
}

interface PreviewResult {
  brief_type: BriefType
  snapshot: Record<string, unknown>
  prompt: string
  gemini_output: string | null
  telegram_message: string
  used_fallback: boolean
  timing_ms: number
}

interface HistoryEntry {
  id: number
  brief_type: BriefType
  trigger_type: string
  sent_at: string
  telegram_ok: boolean
  used_fallback: boolean
  timing_ms: number
  queue_id: number | null
  message_text: string | null
  error: string | null
}

const BRIEF_LABELS: Record<BriefType, string> = {
  morning: 'Morning Operations Brief',
  evening: 'Evening Closure Brief',
  accounts: 'Accounts Snapshot',
}

const BRIEF_TIMES: Record<BriefType, string> = {
  morning: '07:00',
  evening: '19:00',
  accounts: '09:00',
}

const BRIEF_ICONS: Record<BriefType, string> = {
  morning: '🌅',
  evening: '🌆',
  accounts: '💳',
}

export default function SalDashboard() {
  const [config, setConfig] = useState<Config | null>(null)
  const [diagnostics, setDiagnostics] = useState<Diagnostics | null>(null)
  const [configLoading, setConfigLoading] = useState(true)
  const [configError, setConfigError] = useState<string>('')
  const [saving, setSaving] = useState(false)
  const [saveMsg, setSaveMsg] = useState('')
  const [testingTelegram, setTestingTelegram] = useState(false)
  const [testMsg, setTestMsg] = useState('')

  // Preview state
  const [previewType, setPreviewType] = useState<BriefType>('morning')
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewResult, setPreviewResult] = useState<PreviewResult | null>(null)
  const [previewError, setPreviewError] = useState('')
  const [previewTab, setPreviewTab] = useState<'snapshot' | 'prompt' | 'gemini' | 'telegram'>('telegram')

  // Send brief state
  const [sendingType, setSendingType] = useState<BriefType | null>(null)
  const [sendResults, setSendResults] = useState<Record<string, string>>({})

  // History state
  const [history, setHistory] = useState<HistoryEntry[]>([])
  const [historyFilter, setHistoryFilter] = useState<BriefType | 'all'>('all')
  const [historyLoading, setHistoryLoading] = useState(false)
  const [expandedRow, setExpandedRow] = useState<number | null>(null)

  const loadConfig = useCallback(async () => {
    setConfigError('')
    try {
      const data = await apiFetch('/sal/config')
      setConfig(data)
    } catch (e: any) {
      setConfigError(e?.message ?? 'Failed to load SAL configuration.')
    } finally {
      setConfigLoading(false)
    }
  }, [])

  const loadDiagnostics = useCallback(async () => {
    try {
      const data = await apiFetch('/sal/diagnostics')
      setDiagnostics(data)
    } catch {
      // ignore
    }
  }, [])

  const loadHistory = useCallback(async (type: BriefType | 'all' = 'all') => {
    setHistoryLoading(true)
    try {
      const qs = type !== 'all' ? `?type=${type}&limit=100` : '?limit=100'
      const data = await apiFetch(`/sal/history${qs}`)
      setHistory(data.history ?? [])
    } catch {
      // ignore
    } finally {
      setHistoryLoading(false)
    }
  }, [])

  useEffect(() => {
    loadConfig()
    loadDiagnostics()
    loadHistory('all')
  }, [loadConfig, loadDiagnostics, loadHistory])

  const handleSaveConfig = async () => {
    if (!config) return
    setSaving(true)
    setSaveMsg('')
    try {
      await apiFetch('/sal/config', {
        method: 'POST',
        body: JSON.stringify({
          sal_enabled: config.sal_enabled ? '1' : '0',
          sal_morning_brief_enabled: config.sal_morning_brief_enabled ? '1' : '0',
          sal_morning_brief_time: config.sal_morning_brief_time,
          sal_evening_brief_enabled: config.sal_evening_brief_enabled ? '1' : '0',
          sal_evening_brief_time: config.sal_evening_brief_time,
          sal_accounts_snapshot_enabled: config.sal_accounts_snapshot_enabled ? '1' : '0',
          sal_accounts_snapshot_time: config.sal_accounts_snapshot_time,
          sal_telegram_chat_id: config.sal_telegram_chat_id,
        }),
      })
      setSaveMsg('Configuration saved.')
      loadConfig()
      loadDiagnostics()
    } catch (e: any) {
      setSaveMsg('Error: ' + e.message)
    } finally {
      setSaving(false)
    }
  }

  const handleTestTelegram = async () => {
    setTestingTelegram(true)
    setTestMsg('')
    try {
      await apiFetch('/sal/test-telegram', { method: 'POST' })
      setTestMsg('✅ Test message delivered.')
    } catch (e: any) {
      setTestMsg('❌ ' + e.message)
    } finally {
      setTestingTelegram(false)
    }
  }

  const handleGeneratePreview = async () => {
    setPreviewLoading(true)
    setPreviewError('')
    setPreviewResult(null)
    try {
      const data = await apiFetch('/sal/generate', {
        method: 'POST',
        body: JSON.stringify({ brief_type: previewType }),
      })
      setPreviewResult(data)
      setPreviewTab('telegram')
    } catch (e: any) {
      setPreviewError(e.message)
    } finally {
      setPreviewLoading(false)
    }
  }

  const handleSendBrief = async (briefType: BriefType) => {
    setSendingType(briefType)
    setSendResults(prev => ({ ...prev, [briefType]: '' }))
    try {
      const data = await apiFetch('/sal/send', {
        method: 'POST',
        body: JSON.stringify({ brief_type: briefType }),
      })
      const fallback = data.used_fallback ? ' (deterministic fallback)' : ''
      const tg = data.telegram_ok ? '✅ Delivered' : '⚠️ Queued (Telegram delivery failed)'
      setSendResults(prev => ({
        ...prev,
        [briefType]: `${tg} · Queue ID: ${data.queue_id ?? '–'}${fallback}`,
      }))
      loadDiagnostics()
    } catch (e: any) {
      setSendResults(prev => ({ ...prev, [briefType]: '❌ ' + e.message }))
    } finally {
      setSendingType(null)
    }
  }

  const fmtTime = (s: string | null) => {
    if (!s) return '—'
    try { return new Date(s.replace(' ', 'T')).toLocaleString() } catch { return s }
  }

  if (configLoading) {
    return (
      <div style={{ padding: 32 }}>
        <p style={{ color: '#6b7280' }}>Loading SAL configuration…</p>
      </div>
    )
  }

  if (!config) {
    return (
      <div style={{ padding: 32, maxWidth: 560 }}>
        <div style={{
          padding: '20px 24px', background: '#fef2f2', border: '1px solid #fecaca',
          borderRadius: 8,
        }}>
          <h2 style={{ margin: '0 0 8px', fontSize: 16, fontWeight: 700, color: '#991b1b' }}>
            ⚠️ SAL failed to load
          </h2>
          <p style={{ margin: '0 0 12px', fontSize: 13, color: '#7f1d1d' }}>
            {configError || 'The SAL configuration API did not respond. Verify that the plugin is active, the REST API is accessible, and that your account has the required permission (manage_options).'}
          </p>
          <button
            onClick={() => { setConfigLoading(true); loadConfig() }}
            style={{
              padding: '7px 16px', background: '#dc2626', color: '#fff',
              border: 'none', borderRadius: 6, fontSize: 13, cursor: 'pointer', fontWeight: 500,
            }}
          >
            Retry
          </button>
        </div>
      </div>
    )
  }

  const c = config

  return (
    <div style={{ maxWidth: 900, margin: '0 auto', padding: '24px 16px', fontFamily: 'inherit' }}>

      {/* Header */}
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: '#111827' }}>
          🐾 Situational Awareness Layer
        </h1>
        <p style={{ marginTop: 6, color: '#6b7280', fontSize: 14 }}>
          Automated operational briefings delivered to Telegram. Powered by OPB database facts.
        </p>
        <div style={{ marginTop: 10, display: 'flex', alignItems: 'center', gap: 10 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={c.sal_enabled}
              onChange={e => setConfig({ ...c, sal_enabled: e.target.checked })}
              style={{ width: 16, height: 16 }}
            />
            <span style={{ fontWeight: 600, color: c.sal_enabled ? '#059669' : '#dc2626' }}>
              SAL {c.sal_enabled ? 'Enabled' : 'Disabled'}
            </span>
          </label>
        </div>
      </div>

      {/* A. Schedule Configuration */}
      <Section title="A. Schedule Configuration">
        <div style={{ display: 'grid', gap: 20 }}>
          {(['morning', 'evening', 'accounts'] as BriefType[]).map(type => {
            const enabledKey = type === 'accounts' ? 'sal_accounts_snapshot_enabled' : `sal_${type}_brief_enabled` as keyof Config
            const timeKey = type === 'accounts' ? 'sal_accounts_snapshot_time' : `sal_${type}_brief_time` as keyof Config
            const isEnabled = c[enabledKey] as boolean
            const timeVal = c[timeKey] as string

            return (
              <div key={type} style={{
                border: '1px solid #e5e7eb', borderRadius: 8, padding: '16px 18px',
                background: isEnabled ? '#f0fdf4' : '#f9fafb',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span style={{ fontSize: 20 }}>{BRIEF_ICONS[type]}</span>
                    <div>
                      <div style={{ fontWeight: 600, color: '#111827' }}>{BRIEF_LABELS[type]}</div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>
                        Default: {BRIEF_TIMES[type]}
                      </div>
                    </div>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
                      <input
                        type="checkbox"
                        checked={isEnabled}
                        onChange={e => setConfig({ ...c, [enabledKey]: e.target.checked })}
                        style={{ width: 15, height: 15 }}
                      />
                      <span style={{ fontSize: 13, color: '#374151' }}>Enabled</span>
                    </label>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      <label style={{ fontSize: 12, color: '#6b7280' }}>Time:</label>
                      <input
                        type="time"
                        value={timeVal}
                        onChange={e => setConfig({ ...c, [timeKey]: e.target.value })}
                        disabled={!isEnabled}
                        style={{
                          border: '1px solid #d1d5db', borderRadius: 4, padding: '4px 8px',
                          fontSize: 13, background: isEnabled ? '#fff' : '#f3f4f6',
                        }}
                      />
                    </div>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </Section>

      {/* B. Telegram Configuration */}
      <Section title="B. Telegram Configuration">
        <div style={{ display: 'grid', gap: 14 }}>
          <div>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: '#374151', marginBottom: 4 }}>
              SAL Reporting Chat ID
            </label>
            <input
              type="text"
              value={c.sal_telegram_chat_id}
              onChange={e => setConfig({ ...c, sal_telegram_chat_id: e.target.value })}
              placeholder="e.g. -1001234567890"
              style={{
                width: '100%', maxWidth: 320, border: '1px solid #d1d5db', borderRadius: 6,
                padding: '7px 10px', fontSize: 13, boxSizing: 'border-box',
              }}
            />
            <p style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
              {c.sal_telegram_chat_id
                ? '✅ SAL-specific chat ID configured.'
                : c.sal_fallback_chat_id
                ? `Will use main Telegram Chat ID (${c.sal_fallback_chat_id})`
                : '⚠️ No chat ID configured. SAL cannot deliver briefs.'}
            </p>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            <button
              onClick={handleSaveConfig}
              disabled={saving}
              style={btnStyle('#2563eb')}
            >
              {saving ? 'Saving…' : 'Save Configuration'}
            </button>
            <button
              onClick={handleTestTelegram}
              disabled={testingTelegram}
              style={btnStyle('#6b7280')}
            >
              {testingTelegram ? 'Sending…' : 'Send Test Brief'}
            </button>
            {saveMsg && (
              <span style={{ fontSize: 13, color: saveMsg.startsWith('Error') ? '#dc2626' : '#059669' }}>
                {saveMsg}
              </span>
            )}
            {testMsg && (
              <span style={{ fontSize: 13, color: testMsg.startsWith('❌') ? '#dc2626' : '#059669' }}>
                {testMsg}
              </span>
            )}
          </div>
        </div>
      </Section>

      {/* C. Operations */}
      <Section title="C. Operations">
        <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>
          Generate and deliver a brief immediately to the configured Telegram destination.
          These buttons bypass the daily schedule and send without marking today's brief as sent.
        </p>
        <div style={{ display: 'grid', gap: 12 }}>
          {(['morning', 'evening', 'accounts'] as BriefType[]).map(type => (
            <div key={type} style={{
              display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap',
              padding: '12px 14px', border: '1px solid #e5e7eb', borderRadius: 8, background: '#fff',
            }}>
              <span style={{ fontSize: 18 }}>{BRIEF_ICONS[type]}</span>
              <span style={{ fontWeight: 500, color: '#374151', minWidth: 200 }}>{BRIEF_LABELS[type]}</span>
              <button
                onClick={() => handleSendBrief(type)}
                disabled={sendingType === type}
                style={btnStyle('#059669')}
              >
                {sendingType === type ? 'Generating…' : `Generate ${type.charAt(0).toUpperCase() + type.slice(1)} Brief Now`}
              </button>
              {sendResults[type] && (
                <span style={{ fontSize: 13, color: sendResults[type].startsWith('❌') ? '#dc2626' : '#059669' }}>
                  {sendResults[type]}
                </span>
              )}
            </div>
          ))}
        </div>
      </Section>

      {/* Preview Mode */}
      <Section title="Preview Mode">
        <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>
          Inspect each pipeline step before sending: Snapshot JSON → Gemini Prompt → Gemini Output → Telegram Message.
        </p>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
          <select
            value={previewType}
            onChange={e => setPreviewType(e.target.value as BriefType)}
            style={{
              border: '1px solid #d1d5db', borderRadius: 6, padding: '7px 10px',
              fontSize: 13, background: '#fff',
            }}
          >
            <option value="morning">Morning Operations Brief</option>
            <option value="evening">Evening Closure Brief</option>
            <option value="accounts">Accounts Snapshot</option>
          </select>
          <button
            onClick={handleGeneratePreview}
            disabled={previewLoading}
            style={btnStyle('#7c3aed')}
          >
            {previewLoading ? 'Generating…' : 'Generate Snapshot'}
          </button>
        </div>

        {previewError && (
          <div style={{ padding: '10px 14px', background: '#fef2f2', borderRadius: 6, color: '#dc2626', fontSize: 13, marginBottom: 12 }}>
            ❌ {previewError}
          </div>
        )}

        {previewResult && (
          <div>
            {/* Operational window banner — accounts brief only */}
            {previewResult.brief_type === 'accounts' && (previewResult.snapshot as any).op_start && (
              <div style={{
                marginBottom: 12, padding: '8px 14px',
                background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 6,
                fontSize: 13, color: '#1d4ed8', display: 'flex', alignItems: 'center', gap: 8,
              }}>
                <span>📅</span>
                <span>
                  <strong>Operational window active</strong> — figures exclude invoices before{' '}
                  <strong>{(previewResult.snapshot as any).op_start}</strong>
                </span>
              </div>
            )}

            {/* Pipeline tabs */}
            <div style={{ display: 'flex', gap: 0, borderBottom: '1px solid #e5e7eb', marginBottom: 0 }}>
              {([
                { id: 'telegram', label: '📱 Telegram Message' },
                { id: 'gemini',   label: '🤖 Gemini Output' },
                { id: 'prompt',   label: '📝 Gemini Prompt' },
                { id: 'snapshot', label: '📊 Snapshot JSON' },
              ] as { id: typeof previewTab; label: string }[]).map(tab => (
                <button
                  key={tab.id}
                  onClick={() => setPreviewTab(tab.id)}
                  style={{
                    padding: '8px 14px', fontSize: 12, border: 'none',
                    borderBottom: previewTab === tab.id ? '2px solid #7c3aed' : '2px solid transparent',
                    background: 'none', cursor: 'pointer',
                    color: previewTab === tab.id ? '#7c3aed' : '#6b7280',
                    fontWeight: previewTab === tab.id ? 600 : 400,
                  }}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            <div style={{
              background: '#1e1e2e', borderRadius: '0 0 8px 8px',
              padding: '14px 16px', maxHeight: 440, overflowY: 'auto',
            }}>
              {previewTab === 'telegram' && (
                <div>
                  {previewResult.used_fallback && (
                    <div style={{ marginBottom: 10, padding: '6px 10px', background: '#451a03', borderRadius: 4, fontSize: 12, color: '#fbbf24' }}>
                      ⚠️ Gemini unavailable — deterministic fallback used ({previewResult.timing_ms}ms)
                    </div>
                  )}
                  {!previewResult.used_fallback && (
                    <div style={{ marginBottom: 10, fontSize: 12, color: '#86efac' }}>
                      ✅ Gemini formatted ({previewResult.timing_ms}ms)
                    </div>
                  )}
                  <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 13, color: '#e2e8f0', lineHeight: 1.6 }}>
                    {previewResult.telegram_message}
                  </pre>
                </div>
              )}
              {previewTab === 'gemini' && (
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 12, color: '#e2e8f0', lineHeight: 1.5 }}>
                  {previewResult.gemini_output ?? '— Gemini was not called (API key missing) or returned no output. Deterministic fallback was used.'}
                </pre>
              )}
              {previewTab === 'prompt' && (
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 11, color: '#c4b5fd', lineHeight: 1.5 }}>
                  {previewResult.prompt}
                </pre>
              )}
              {previewTab === 'snapshot' && (
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 11, color: '#86efac', lineHeight: 1.4 }}>
                  {JSON.stringify(previewResult.snapshot, null, 2)}
                </pre>
              )}
            </div>
          </div>
        )}
      </Section>

      {/* D. Brief History */}
      <Section title="D. Brief History">
        <div style={{ marginBottom: 12, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          {(['all', 'morning', 'evening', 'accounts'] as const).map(f => (
            <button
              key={f}
              onClick={() => {
                setHistoryFilter(f)
                setExpandedRow(null)
                loadHistory(f)
              }}
              style={{
                ...btnStyle(historyFilter === f ? '#1d4ed8' : '#9ca3af'),
                fontSize: 12, padding: '5px 12px',
              }}
            >
              {f === 'all' ? 'All' : BRIEF_LABELS[f]}
            </button>
          ))}
          <button
            onClick={() => { setExpandedRow(null); loadHistory(historyFilter) }}
            disabled={historyLoading}
            style={{ ...btnStyle('#6b7280'), fontSize: 12, padding: '5px 10px' }}
          >
            {historyLoading ? 'Loading…' : '↻ Refresh'}
          </button>
        </div>

        {history.length === 0 ? (
          <p style={{ color: '#9ca3af', fontSize: 13 }}>
            {historyLoading ? 'Loading…' : 'No briefs sent yet.'}
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ background: '#f9fafb', borderBottom: '1px solid #e5e7eb' }}>
                  {['Sent At', 'Type', 'Trigger', 'Telegram', 'Fallback', 'Time (ms)', ''].map(h => (
                    <th key={h} style={{ padding: '8px 10px', textAlign: 'left', fontWeight: 600, color: '#374151', whiteSpace: 'nowrap' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {history.map(row => (
                  <>
                    <tr
                      key={row.id}
                      style={{
                        borderBottom: '1px solid #f3f4f6',
                        background: expandedRow === row.id ? '#f0f9ff' : '#fff',
                        cursor: 'pointer',
                      }}
                      onClick={() => setExpandedRow(expandedRow === row.id ? null : row.id)}
                    >
                      <td style={{ padding: '8px 10px', whiteSpace: 'nowrap', color: '#374151' }}>{fmtTime(row.sent_at)}</td>
                      <td style={{ padding: '8px 10px', whiteSpace: 'nowrap' }}>
                        <span>{BRIEF_ICONS[row.brief_type]} {BRIEF_LABELS[row.brief_type]}</span>
                      </td>
                      <td style={{ padding: '8px 10px', color: '#6b7280' }}>
                        {row.trigger_type === 'scheduled' ? '🕐 Scheduled' : '👆 Manual'}
                      </td>
                      <td style={{ padding: '8px 10px' }}>
                        {row.telegram_ok
                          ? <span style={{ color: '#059669', fontWeight: 500 }}>✅ Sent</span>
                          : <span style={{ color: '#dc2626', fontWeight: 500 }}>❌ Failed</span>}
                      </td>
                      <td style={{ padding: '8px 10px', color: '#6b7280' }}>
                        {row.used_fallback ? '⚙️ Yes' : '✨ Gemini'}
                      </td>
                      <td style={{ padding: '8px 10px', color: '#6b7280', textAlign: 'right' }}>
                        {row.timing_ms > 0 ? row.timing_ms.toLocaleString() : '—'}
                      </td>
                      <td style={{ padding: '8px 10px', color: '#9ca3af', fontSize: 11 }}>
                        {expandedRow === row.id ? '▲' : '▼'}
                      </td>
                    </tr>
                    {expandedRow === row.id && (
                      <tr key={`${row.id}-detail`} style={{ background: '#f0f9ff' }}>
                        <td colSpan={7} style={{ padding: '10px 14px' }}>
                          {row.error && (
                            <div style={{ marginBottom: 8, fontSize: 12, color: '#dc2626', background: '#fef2f2', padding: '6px 8px', borderRadius: 4 }}>
                              <strong>Error:</strong> {row.error}
                            </div>
                          )}
                          {row.message_text ? (
                            <pre style={{
                              margin: 0, fontSize: 12, whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                              background: '#1e293b', color: '#e2e8f0', padding: 12, borderRadius: 6,
                              maxHeight: 300, overflowY: 'auto',
                            }}>
                              {row.message_text}
                            </pre>
                          ) : (
                            <p style={{ margin: 0, fontSize: 12, color: '#9ca3af' }}>No message text recorded.</p>
                          )}
                          {row.queue_id && (
                            <div style={{ marginTop: 6, fontSize: 11, color: '#6b7280' }}>
                              Queue ID: #{row.queue_id}
                            </div>
                          )}
                        </td>
                      </tr>
                    )}
                  </>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      {/* E. Diagnostics */}
      <Section title="E. Diagnostics">
        {diagnostics ? (
          <div>
            <div style={{ marginBottom: 12 }}>
              <span style={{ fontSize: 13, color: diagnostics.cron_active ? '#059669' : '#dc2626', fontWeight: 500 }}>
                {diagnostics.cron_active ? '✅ SAL cron job is active' : '⚠️ SAL cron job is not scheduled'}
              </span>
            </div>
            <div style={{ display: 'grid', gap: 12 }}>
              {(['morning', 'evening', 'accounts'] as BriefType[]).map(type => {
                const d = diagnostics.diagnostics[type]
                return (
                  <div key={type} style={{
                    border: '1px solid #e5e7eb', borderRadius: 8, padding: '14px 16px', background: '#fff',
                  }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                      <span>{BRIEF_ICONS[type]}</span>
                      <span style={{ fontWeight: 600, color: '#111827', fontSize: 14 }}>{BRIEF_LABELS[type]}</span>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 8 }}>
                      <DiagRow label="Last Run"      value={fmtTime(d.last_run)} />
                      <DiagRow label="Last Success"  value={fmtTime(d.last_success)} color="#059669" />
                      <DiagRow label="Last Failure"  value={fmtTime(d.last_failure)} color="#dc2626" />
                      <DiagRow label="Next Scheduled" value={fmtTime(d.next_run)} />
                    </div>
                    {d.last_error && (
                      <div style={{ marginTop: 8, fontSize: 12, color: '#dc2626', background: '#fef2f2', padding: '6px 8px', borderRadius: 4 }}>
                        Last error: {d.last_error}
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
            <button
              onClick={loadDiagnostics}
              style={{ ...btnStyle('#6b7280'), marginTop: 12, fontSize: 12 }}
            >
              Refresh Diagnostics
            </button>
          </div>
        ) : (
          <p style={{ color: '#6b7280', fontSize: 13 }}>Loading diagnostics…</p>
        )}
      </Section>

    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div style={{ marginBottom: 28 }}>
      <h2 style={{
        margin: '0 0 14px 0', fontSize: 15, fontWeight: 600, color: '#374151',
        paddingBottom: 8, borderBottom: '1px solid #e5e7eb',
      }}>
        {title}
      </h2>
      {children}
    </div>
  )
}

function DiagRow({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: '#9ca3af', marginBottom: 2 }}>{label}</div>
      <div style={{ fontSize: 13, color: color ?? '#374151', fontWeight: 500 }}>{value}</div>
    </div>
  )
}

function btnStyle(bg: string): React.CSSProperties {
  return {
    background: bg, color: '#fff', border: 'none', borderRadius: 6,
    padding: '8px 14px', fontSize: 13, fontWeight: 500, cursor: 'pointer',
    whiteSpace: 'nowrap',
  }
}
