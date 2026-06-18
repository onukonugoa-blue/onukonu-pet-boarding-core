import { useState, useEffect, useCallback } from 'react'
import { opsmailApi, type OpsmailStats, type GeminiRunResult } from '../../api/opsmail'
import { ApiError } from '../../api/client'

const SAMPLE_TEXT = `Patient called and requested appointment rescheduling from Friday to Monday because of travel delays. Client is Priya Sharma (Bruno's owner). Booking #1042 at Koramangala branch. Check-in originally 20 Jun, needs to move to 23 Jun. She also mentioned she wants to pay the balance on arrival. Please confirm availability and update the booking.`

function canAccess(): boolean {
  const roles: string[] = window.OPB?.user?.roles ?? []
  return roles.includes('administrator') || roles.includes('opb_super_admin')
}

function StatusChip({ ok, label, sublabel }: { ok: boolean; label: string; sublabel?: string }) {
  return (
    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border ${
      ok
        ? 'bg-green-50 border-green-200 text-green-700'
        : 'bg-red-50  border-red-200  text-red-700'
    }`}>
      <span className="inline-block w-1.5 h-1.5 rounded-full" style={{ background: 'currentColor' }} />
      {label}{sublabel ? ` — ${sublabel}` : ''}
    </span>
  )
}

function Collapsible({ title, children }: { title: string; children: React.ReactNode }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="border border-gray-200 rounded overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between px-3 py-2 text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 text-left transition-colors"
      >
        <span>{title}</span>
        <span className="text-gray-400">{open ? '▲' : '▼'}</span>
      </button>
      {open && (
        <div className="px-3 py-2 bg-white">
          {children}
        </div>
      )}
    </div>
  )
}

export default function GeminiLab() {
  const [stats, setStats]               = useState<OpsmailStats | null>(null)
  const [input, setInput]               = useState('')
  const [running, setRunning]           = useState(false)
  const [result, setResult]             = useState<GeminiRunResult | null>(null)
  const [error, setError]               = useState('')
  const [tgStatus, setTgStatus]         = useState<{ ok: boolean; msg: string } | null>(null)
  const [tgSending, setTgSending]       = useState(false)

  useEffect(() => {
    opsmailApi.getStats().then(setStats).catch(() => {})
  }, [])

  const run = useCallback(async (text: string, sendTelegram = false) => {
    if (!text.trim()) return
    setRunning(true)
    setError('')
    setResult(null)
    setTgStatus(null)
    try {
      const res = await opsmailApi.geminiRun({ text, send_telegram: sendTelegram })
      setResult(res)
      if (sendTelegram) {
        setTgStatus({
          ok:  res.telegram?.ok ?? false,
          msg: res.telegram?.ok
            ? 'Result delivered to Telegram.'
            : (res.telegram?.error ?? 'Telegram delivery failed.'),
        })
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Request failed — check the console.')
    } finally {
      setRunning(false)
    }
  }, [])

  const handleSendToTelegram = async () => {
    if (!result || !input.trim()) return
    setTgSending(true)
    setTgStatus(null)
    try {
      const res = await opsmailApi.geminiRun({ text: input, send_telegram: true })
      setTgStatus({
        ok:  res.telegram?.ok ?? false,
        msg: res.telegram?.ok
          ? 'Result delivered to Telegram.'
          : (res.telegram?.error ?? 'Telegram delivery failed.'),
      })
    } catch (e) {
      setTgStatus({ ok: false, msg: e instanceof ApiError ? e.message : 'Send failed.' })
    } finally {
      setTgSending(false)
    }
  }

  const handleE2ETest = () => {
    setInput(SAMPLE_TEXT)
    run(SAMPLE_TEXT, true)
  }

  const handleClear = () => {
    setInput('')
    setResult(null)
    setError('')
    setTgStatus(null)
  }

  if (!canAccess()) {
    return (
      <div className="card max-w-md mx-auto mt-12 text-center">
        <p className="text-gray-500 text-sm">You do not have permission to view this page.</p>
      </div>
    )
  }

  const parsed = result?.parsed

  return (
    <div className="max-w-4xl">

      {/* ── Header ── */}
      <div className="mb-5">
        <h1 className="page-title mb-1">OPSMAIL — Gemini Lab</h1>
        <p className="text-sm text-gray-500">
          Test arbitrary operational text through Gemini and optionally deliver results to Telegram.
        </p>
      </div>

      {/* ── Status chips ── */}
      <div className="flex flex-wrap gap-2 mb-6">
        {stats ? (
          <>
            <StatusChip
              ok={stats.gemini_configured}
              label={stats.gemini_configured ? 'Gemini Connected' : 'Gemini Not Configured'}
              sublabel={stats.gemini_configured ? (stats.gemini_model || 'gemini-2.5-flash') : undefined}
            />
            <StatusChip
              ok={stats.telegram_configured}
              label={stats.telegram_configured ? 'Telegram Connected' : 'Telegram Not Configured'}
            />
          </>
        ) : (
          <>
            <div className="h-7 w-48 rounded-full bg-gray-100 animate-pulse" />
            <div className="h-7 w-44 rounded-full bg-gray-100 animate-pulse" />
          </>
        )}
      </div>

      {/* ── Input card ── */}
      <div className="card mb-4">
        <label className="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
          Input Text
        </label>
        <textarea
          value={input}
          onChange={e => setInput(e.target.value)}
          rows={8}
          placeholder="Paste any operational text here — client messages, emails, staff notes, support requests…"
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-y font-mono mb-1"
        />
        <p className="text-xs text-gray-400 mb-4">{input.length.toLocaleString()} characters</p>

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => run(input, false)}
            disabled={running || !input.trim() || !stats?.gemini_configured}
            className="px-4 py-2 rounded text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {running ? '⏳ Processing…' : '▶ Process with Gemini'}
          </button>

          {result && (
            <button
              type="button"
              onClick={handleSendToTelegram}
              disabled={tgSending || !stats?.telegram_configured}
              className="px-4 py-2 rounded text-sm font-medium bg-slate-700 text-white hover:bg-slate-800 disabled:opacity-50 transition-colors"
            >
              {tgSending ? '⏳ Sending…' : '📡 Send Result to Telegram'}
            </button>
          )}

          <button
            type="button"
            onClick={handleE2ETest}
            disabled={running || !stats?.gemini_configured || !stats?.telegram_configured}
            className="px-4 py-2 rounded text-sm font-medium bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-50 transition-colors"
            title="Pre-fills the textarea with a sample booking message and runs end-to-end: Gemini classify + Telegram delivery"
          >
            🧪 End-to-End Test
          </button>

          {(input || result) && (
            <button
              type="button"
              onClick={handleClear}
              className="px-4 py-2 rounded text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
            >
              ✕ Clear
            </button>
          )}
        </div>

        {!stats?.gemini_configured && (
          <p className="mt-3 text-xs text-amber-700 bg-amber-50 rounded px-3 py-2 border border-amber-200">
            ⚠ Gemini API key is not configured. Go to{' '}
            <strong>Settings → Customization → OPSMAIL</strong> to add it.
          </p>
        )}
      </div>

      {/* ── Error ── */}
      {error && (
        <div className="mb-4 px-4 py-3 rounded-lg border text-sm bg-red-50 border-red-200 text-red-700">
          ⚠ {error}
        </div>
      )}

      {/* ── Telegram status ── */}
      {tgStatus && (
        <div className={`mb-4 px-4 py-3 rounded-lg border text-sm font-medium ${
          tgStatus.ok
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-700'
        }`}>
          {tgStatus.ok ? '✓' : '✗'} {tgStatus.msg}
        </div>
      )}

      {/* ── Output ── */}
      {result && (
        <div className="space-y-4">

          {/* Parsed output card */}
          <div className="card">
            <div className="flex items-start justify-between gap-4 mb-4">
              <h2 className="text-sm font-bold text-gray-800 uppercase tracking-wide">📊 Parsed Output</h2>
              <div className="flex items-center gap-3 text-xs text-gray-500 shrink-0">
                <span title="Round-trip time to Gemini API">
                  ⏱ {result.timing_ms.toLocaleString()} ms
                </span>
                {result.usage && (
                  <span title="Token usage: prompt / output / total">
                    🪙 {result.usage.promptTokenCount ?? '?'} in
                    {' · '}
                    {result.usage.candidatesTokenCount ?? '?'} out
                    {' · '}
                    {result.usage.totalTokenCount ?? '?'} total
                  </span>
                )}
              </div>
            </div>

            {parsed ? (
              <div className="space-y-4">
                <div>
                  <p className="text-xs text-gray-500 font-medium mb-1">Summary</p>
                  <p className="text-sm text-gray-900 leading-relaxed">{parsed.summary}</p>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  <div className="bg-gray-50 rounded-lg px-3 py-2.5">
                    <p className="text-xs text-gray-500 mb-1">Category</p>
                    <p className="text-sm font-semibold text-gray-800 break-words">{parsed.category}</p>
                  </div>

                  <div className="bg-gray-50 rounded-lg px-3 py-2.5">
                    <p className="text-xs text-gray-500 mb-1">Priority</p>
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold ${
                      parsed.priority === 'HIGH'
                        ? 'bg-red-100 text-red-700'
                        : 'bg-green-100 text-green-700'
                    }`}>
                      {parsed.priority === 'HIGH' ? '🔴' : '✅'} {parsed.priority}
                    </span>
                  </div>

                  <div className="bg-gray-50 rounded-lg px-3 py-2.5">
                    <p className="text-xs text-gray-500 mb-1">Action Required</p>
                    <p className={`text-sm font-semibold ${parsed.action_required ? 'text-orange-600' : 'text-gray-500'}`}>
                      {parsed.action_required ? 'Yes' : 'No'}
                    </p>
                  </div>

                  <div className="bg-gray-50 rounded-lg px-3 py-2.5">
                    <p className="text-xs text-gray-500 mb-1">Confidence</p>
                    <p className="text-sm font-semibold text-gray-800">
                      {typeof parsed.confidence === 'number'
                        ? `${Math.round(parsed.confidence * 100)}%`
                        : '—'}
                    </p>
                  </div>
                </div>
              </div>
            ) : (
              <div>
                <p className="text-xs text-gray-500 mb-1">Response (could not parse as JSON)</p>
                <pre className="text-xs text-gray-700 bg-gray-50 rounded p-3 whitespace-pre-wrap overflow-x-auto">
                  {result.response}
                </pre>
              </div>
            )}
          </div>

          {/* Telegram payload */}
          {result.telegram_payload && (
            <div className="card">
              <h2 className="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                📡 Telegram Payload
              </h2>
              <pre className="text-xs text-gray-700 bg-slate-50 border border-slate-200 rounded p-3 whitespace-pre-wrap overflow-x-auto">
                {result.telegram_payload}
              </pre>
            </div>
          )}

          {/* Collapsible technical panels */}
          <div className="space-y-2">
            <Collapsible title="Prompt Sent to Gemini">
              <pre className="text-xs text-gray-600 whitespace-pre-wrap overflow-x-auto max-h-64">
                {result.prompt}
              </pre>
            </Collapsible>

            <Collapsible title="Raw Gemini Response (JSON)">
              <pre className="text-xs text-gray-600 whitespace-pre-wrap overflow-x-auto max-h-64">
                {result.response}
              </pre>
            </Collapsible>

            {input && (
              <Collapsible title="Raw Input">
                <pre className="text-xs text-gray-600 whitespace-pre-wrap overflow-x-auto max-h-48">
                  {input}
                </pre>
              </Collapsible>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
