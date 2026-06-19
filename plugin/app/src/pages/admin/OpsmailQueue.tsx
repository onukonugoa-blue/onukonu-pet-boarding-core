import { useState, useEffect, useCallback, useRef } from 'react'
import { opsmailApi, type OpsmailQueueEvent, type OpsmailStats, type CronHealth, type CronComponentStatus } from '../../api/opsmail'
import { ApiError, fmt } from '../../api/client'

// ── Telegram status badge ───────────────────────────────────────────────────────

function TelegramBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    PENDING: 'bg-yellow-100 text-yellow-800',
    SENT:    'bg-green-100 text-green-800',
    FAILED:  'bg-red-100 text-red-700',
  }
  const cls = map[status] ?? 'bg-gray-100 text-gray-600'
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${cls}`}>
      {status}
    </span>
  )
}

// ── Telegram panel ─────────────────────────────────────────────────────────────

interface TelegramPanelProps {
  stats: OpsmailStats
  onDone: () => void
}

function TelegramPanel({ stats, onDone }: TelegramPanelProps) {
  const [testing, setTesting]     = useState(false)
  const [testMsg, setTestMsg]     = useState<{ ok: boolean; text: string } | null>(null)
  const [flushing, setFlushing]   = useState(false)
  const [flushLog, setFlushLog]   = useState<string | null>(null)

  const handleTest = async () => {
    setTesting(true)
    setTestMsg(null)
    try {
      const res = await opsmailApi.testTelegram()
      setTestMsg({ ok: true, text: res.message })
    } catch (e) {
      setTestMsg({ ok: false, text: e instanceof ApiError ? e.message : 'Test failed — check bot token and chat ID.' })
    } finally {
      setTesting(false)
    }
  }

  const handleFlush = async () => {
    setFlushing(true)
    setFlushLog(null)
    try {
      const res = await opsmailApi.processTelegram(50)
      const summary = res.log.at(-1) as Record<string, unknown> | undefined
      const delivered = summary?.delivered ?? '—'
      const total     = summary?.total     ?? '—'
      setFlushLog(`Processed ${total} entries — ${delivered} delivered.`)
      onDone()
    } catch (e) {
      setFlushLog(e instanceof ApiError ? e.message : 'Flush failed.')
    } finally {
      setFlushing(false)
    }
  }

  const { by_telegram_status: tg, telegram_configured, last_telegram_sent_at, recent_telegram_failed } = stats

  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 px-5 py-4 mb-6">
      <div className="flex items-center gap-2 mb-4">
        <span className="text-base">📡</span>
        <h2 className="text-sm font-bold text-slate-800 uppercase tracking-wide">Telegram Delivery</h2>
        <span className={`ml-auto inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${
          telegram_configured
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-red-50 border-red-200 text-red-700'
        }`}>
          <span className="w-1.5 h-1.5 rounded-full inline-block" style={{ background: 'currentColor' }} />
          {telegram_configured ? 'Connected' : 'Not configured'}
        </span>
      </div>

      {/* Telegram status counts */}
      <div className="grid grid-cols-3 gap-3 mb-4">
        {([
          { label: 'Pending', value: tg.PENDING, cls: 'text-yellow-700 bg-yellow-50 border-yellow-200' },
          { label: 'Sent',    value: tg.SENT,    cls: 'text-green-700 bg-green-50 border-green-200'   },
          { label: 'Failed',  value: tg.FAILED,  cls: 'text-red-700 bg-red-50 border-red-200'         },
        ] as const).map((t) => (
          <div key={t.label} className={`rounded-lg border px-3 py-2 ${t.cls}`}>
            <div className="text-xl font-bold">{t.value}</div>
            <div className="text-xs font-medium opacity-80 uppercase tracking-wide">{t.label}</div>
          </div>
        ))}
      </div>

      {/* Last sent + actions */}
      <div className="flex flex-wrap items-center gap-3 mb-3">
        <span className="text-xs text-slate-500">
          Last delivery:{' '}
          <span className="font-medium text-slate-700">
            {last_telegram_sent_at
              ? new Date(last_telegram_sent_at).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
              : 'Never'}
          </span>
        </span>

        <div className="flex gap-2 ml-auto">
          <button
            onClick={handleTest}
            disabled={testing || !telegram_configured}
            className="px-3 py-1.5 rounded text-xs font-medium border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-50 transition-colors"
          >
            {testing ? 'Sending…' : '🧪 Test Message'}
          </button>
          <button
            onClick={handleFlush}
            disabled={flushing || !telegram_configured || tg.PENDING === 0}
            className="px-3 py-1.5 rounded text-xs font-medium bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {flushing ? 'Flushing…' : `⚡ Flush Queue (${tg.PENDING} pending)`}
          </button>
        </div>
      </div>

      {/* Test result */}
      {testMsg && (
        <div className={`mb-3 px-3 py-2 rounded text-xs border ${
          testMsg.ok
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-700'
        }`}>
          {testMsg.ok ? '✓ ' : '⚠ '}{testMsg.text}
        </div>
      )}

      {/* Flush result */}
      {flushLog && (
        <div className="mb-3 px-3 py-2 rounded text-xs border bg-blue-50 border-blue-200 text-blue-800">
          ⚡ {flushLog}
        </div>
      )}

      {/* Recent Telegram failures */}
      {recent_telegram_failed.length > 0 && (
        <div className="rounded border border-red-200 bg-red-50 px-3 py-2.5 mt-1">
          <p className="text-xs font-semibold text-red-800 mb-1.5 uppercase tracking-wide">Recent Telegram Failures</p>
          <div className="space-y-1.5">
            {recent_telegram_failed.map((f) => (
              <div key={f.id} className="text-xs text-red-700 flex flex-wrap gap-2">
                <span className="font-medium">#{f.id}</span>
                <span className="font-mono text-red-500">{f.event_type}</span>
                <span className="truncate max-w-[12rem]">{f.subject}</span>
                <span className="text-red-400">attempts: {f.telegram_attempts}</span>
                {f.last_error && (
                  <span className="italic text-red-400 truncate max-w-[14rem]">— {f.last_error}</span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Cron Health Panel ──────────────────────────────────────────────────────────

function elapsedLabel(sec: number | null): string {
  if (sec === null) return 'Never'
  if (sec < 60)    return `${sec}s ago`
  if (sec < 3600)  return `${Math.floor(sec / 60)}m ago`
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  return m > 0 ? `${h}h ${m}m ago` : `${h}h ago`
}

function StatusChip({ status }: { status: 'healthy' | 'delayed' | 'not_running' }) {
  const map = {
    healthy:     { cls: 'bg-green-50 border-green-200 text-green-700', icon: '✓', label: 'Healthy' },
    delayed:     { cls: 'bg-amber-50 border-amber-200 text-amber-700', icon: '⚠', label: 'Delayed' },
    not_running: { cls: 'bg-red-50 border-red-200 text-red-700',       icon: '✗', label: 'Not Running' },
  }
  const { cls, icon, label } = map[status]
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border ${cls}`}>
      {icon} {label}
    </span>
  )
}

function ExternalCronBadge({ status }: { status: CronHealth['external_cron'] }) {
  const map = {
    detected:     { cls: 'bg-green-50 border-green-200 text-green-700', label: 'Detected' },
    unknown:      { cls: 'bg-gray-50 border-gray-200 text-gray-600',    label: 'Unknown' },
    not_detected: { cls: 'bg-amber-50 border-amber-200 text-amber-700', label: 'Not Detected' },
  }
  const { cls, label } = map[status]
  return (
    <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border ${cls}`}>
      {label}
    </span>
  )
}

function ComponentRow({ label, icon, data }: { label: string; icon: string; data: CronComponentStatus }) {
  return (
    <div className="flex items-center gap-3 py-2 border-b border-slate-100 last:border-0">
      <span className="text-base w-5 shrink-0">{icon}</span>
      <span className="text-sm font-medium text-slate-700 w-36 shrink-0">{label}</span>
      <StatusChip status={data.status} />
      <span className="text-xs text-slate-500 ml-auto">
        {data.last_run ? elapsedLabel(data.elapsed_sec) : 'Never run'}
      </span>
    </div>
  )
}

function CronHealthPanel() {
  const [data, setData]         = useState<CronHealth | null>(null)
  const [loading, setLoading]   = useState(true)
  const [err, setErr]           = useState('')
  const [copied, setCopied]     = useState(false)
  const [showSetup, setShowSetup] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    setErr('')
    opsmailApi.getCronHealth()
      .then(setData)
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load scheduler health.'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  const handleCopy = () => {
    if (!data) return
    navigator.clipboard.writeText(data.recommended_cron_command).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  if (loading) {
    return <div className="h-28 rounded-lg border border-gray-200 bg-gray-50 animate-pulse mb-6" />
  }

  if (err || !data) {
    return (
      <div className="mb-6 px-4 py-3 rounded-lg border border-red-200 bg-red-50 text-xs text-red-700">
        ⚠ Scheduler health unavailable: {err || 'No data'}
      </div>
    )
  }

  const needsWarning = data.external_cron !== 'detected'

  return (
    <div className="mb-6 space-y-3">

      {/* Warning banner */}
      {needsWarning && (
        <div className="flex gap-3 items-start px-4 py-3 rounded-lg border border-amber-300 bg-amber-50">
          <span className="text-amber-500 text-lg mt-0.5 shrink-0">⚠</span>
          <div>
            <p className="text-sm font-semibold text-amber-800">
              OPSMAIL is currently relying on visitor-triggered WP-Cron.
            </p>
            <p className="text-xs text-amber-700 mt-0.5">
              Scheduled Telegram notifications and SAL briefings may execute late.
              For production deployments, configure a server cron job below.
            </p>
          </div>
        </div>
      )}

      {/* Status panel */}
      <div className="rounded-lg border border-slate-200 bg-slate-50 px-5 py-4">
        <div className="flex items-center gap-2 mb-4">
          <span className="text-base">⚙</span>
          <h2 className="text-sm font-bold text-slate-800 uppercase tracking-wide">Scheduler Health</h2>
          <StatusChip status={data.overall_status} />
          <button
            onClick={load}
            className="ml-auto text-xs text-slate-500 hover:text-slate-700 border border-slate-200 rounded px-2 py-1 bg-white hover:bg-slate-100 transition-colors"
          >
            ↻ Refresh
          </button>
        </div>

        {/* Component rows */}
        <div className="rounded-lg border border-slate-200 bg-white px-4 mb-4">
          <ComponentRow label="Telegram Queue"    icon="📡" data={data.components.queue}   />
          <ComponentRow label="Mailbox Processor" icon="📥" data={data.components.mailbox} />
          <ComponentRow label="SAL Scheduler"     icon="🧠" data={data.components.sal}     />
        </div>

        {/* Meta row */}
        <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
          <span>
            External Cron:{' '}
            <ExternalCronBadge status={data.external_cron} />
          </span>
          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-medium ${
            data.cron_active
              ? 'bg-green-50 border-green-200 text-green-700'
              : 'bg-red-50 border-red-200 text-red-700'
          }`}>
            WP-Cron {data.cron_active ? 'Active' : 'Not scheduled'}
          </span>
          {data.wp_cron_disabled && (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-medium bg-blue-50 border-blue-200 text-blue-700">
              DISABLE_WP_CRON = true
            </span>
          )}
        </div>
      </div>

      {/* External Cron Setup */}
      <div className="rounded-lg border border-slate-200 bg-white">
        <button
          onClick={() => setShowSetup((v) => !v)}
          className="w-full flex items-center justify-between px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors rounded-lg"
        >
          <span className="flex items-center gap-2">
            <span>🖥</span> External Cron Setup — Hostinger cPanel
          </span>
          <span className="text-slate-400 text-xs">{showSetup ? '▲ Hide' : '▼ Show'}</span>
        </button>

        {showSetup && (
          <div className="border-t border-slate-100 px-5 py-4 space-y-4 text-sm">

            {/* Recommended command */}
            <div>
              <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1.5">
                Recommended Cron Command
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 block bg-slate-900 text-green-400 text-xs rounded px-3 py-2.5 font-mono break-all">
                  {data.recommended_cron_command}
                </code>
                <button
                  onClick={handleCopy}
                  className="shrink-0 px-3 py-2 rounded text-xs font-medium border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition-colors"
                >
                  {copied ? '✓ Copied' : 'Copy'}
                </button>
              </div>
              <p className="text-xs text-slate-500 mt-1.5">
                Frequency: <span className="font-mono font-medium">{data.recommended_frequency}</span>
                {' '}(every 5 minutes)
              </p>
            </div>

            {/* Step-by-step */}
            <div>
              <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">
                Setup Steps
              </p>
              <ol className="space-y-3">
                {[
                  {
                    n: 1,
                    title: 'Open Cron Jobs in Hostinger',
                    body: 'Log in to hPanel → Hosting → Manage → Cron Jobs.',
                  },
                  {
                    n: 2,
                    title: 'Create a new cron job',
                    body: 'Click "Create New Cron Job".',
                  },
                  {
                    n: 3,
                    title: 'Set the frequency',
                    body: (
                      <span>
                        Select <strong>Custom</strong> and enter:{' '}
                        <code className="bg-slate-100 px-1 rounded font-mono">{data.recommended_frequency}</code>
                        {' '}— this runs every 5 minutes.
                      </span>
                    ),
                  },
                  {
                    n: 4,
                    title: 'Enter the command',
                    body: (
                      <span>
                        Paste the command shown above into the Command field.
                        The URL is auto-generated from your site:{' '}
                        <code className="bg-slate-100 px-1 rounded font-mono text-xs break-all">{data.wp_cron_url}</code>
                      </span>
                    ),
                  },
                  {
                    n: 5,
                    title: 'Save and wait 10 minutes',
                    body: 'Click Save. Within 10 minutes the Scheduler Health above will show "Detected" for External Cron.',
                  },
                  {
                    n: 6,
                    title: 'Verify',
                    body: 'Return to this page and click ↻ Refresh. All components should show ✓ Healthy.',
                  },
                ].map(({ n, title, body }) => (
                  <li key={n} className="flex gap-3">
                    <span className="shrink-0 w-6 h-6 rounded-full bg-slate-700 text-white text-xs flex items-center justify-center font-bold">
                      {n}
                    </span>
                    <div>
                      <p className="font-semibold text-slate-700">{title}</p>
                      <p className="text-xs text-slate-500 mt-0.5">{body}</p>
                    </div>
                  </li>
                ))}
              </ol>
            </div>

            {/* Why every 5 minutes */}
            <div className="rounded border border-blue-100 bg-blue-50 px-3 py-2.5">
              <p className="text-xs font-semibold text-blue-800 mb-1">Why every 5 minutes?</p>
              <ul className="text-xs text-blue-700 space-y-0.5 list-disc list-inside">
                <li>Telegram queue processes within 5 minutes of an event.</li>
                <li>SAL briefings fire near their scheduled times (07:00, 18:00, 22:00).</li>
                <li>Future automations stay reliable without visitor dependency.</li>
              </ul>
            </div>

            {/* Flow diagram */}
            <div className="rounded border border-slate-200 bg-slate-50 px-3 py-2.5">
              <p className="text-xs font-semibold text-slate-600 mb-2 uppercase tracking-wide">Required Flow</p>
              <div className="text-xs text-slate-600 font-mono space-y-0.5">
                {['Linux Cron (*/5 * * * *)', '↓', 'WP-Cron (wp-cron.php)', '↓', 'OPSMAIL Scheduler', '↓', 'Queue + SAL', '↓', 'Telegram Consumer', '↓', 'Telegram'].map((line, i) => (
                  <div key={i} className={line === '↓' ? 'text-slate-400 pl-8' : ''}>{line}</div>
                ))}
              </div>
            </div>

          </div>
        )}
      </div>

    </div>
  )
}

// ── Permission guard ───────────────────────────────────────────────────────────

function canAccess(): boolean {
  const roles: string[] = window.OPB?.user?.roles ?? []
  return roles.includes('administrator') || roles.includes('opb_super_admin')
}

// ── Status badge ───────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    PENDING:      'bg-yellow-100 text-yellow-800',
    SENT:         'bg-green-100 text-green-800',
    FAILED:       'bg-red-100 text-red-700',
    ACKNOWLEDGED: 'bg-gray-100 text-gray-600',
  }
  const cls = map[status] ?? 'bg-gray-100 text-gray-600'
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${cls}`}>
      {status}
    </span>
  )
}

// ── Stats bar ─────────────────────────────────────────────────────────────────

function StatsBar({ stats }: { stats: OpsmailStats }) {
  const tiles = [
    { label: 'Pending',      value: stats.by_mail_status.PENDING,      cls: 'text-yellow-700 bg-yellow-50 border-yellow-200' },
    { label: 'Sent',         value: stats.by_mail_status.SENT,         cls: 'text-green-700 bg-green-50 border-green-200' },
    { label: 'Failed',       value: stats.by_mail_status.FAILED,       cls: 'text-red-700 bg-red-50 border-red-200' },
    { label: 'Acknowledged', value: stats.by_mail_status.ACKNOWLEDGED, cls: 'text-gray-600 bg-gray-50 border-gray-200' },
  ]

  return (
    <div className="space-y-3 mb-6">
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {tiles.map((t) => (
          <div key={t.label} className={`rounded-lg border px-4 py-3 ${t.cls}`}>
            <div className="text-2xl font-bold">{t.value}</div>
            <div className="text-xs font-medium mt-0.5 uppercase tracking-wide opacity-80">{t.label}</div>
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 text-sm">
        <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium ${
          stats.opsmail_enabled
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-red-50 border-red-200 text-red-700'
        }`}>
          <span className="w-1.5 h-1.5 rounded-full inline-block" style={{ background: 'currentColor' }} />
          OPSMAIL {stats.opsmail_enabled ? 'Enabled' : 'Disabled'}
        </span>
        <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium ${
          stats.inbox_configured
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-amber-50 border-amber-200 text-amber-700'
        }`}>
          <span className="w-1.5 h-1.5 rounded-full inline-block" style={{ background: 'currentColor' }} />
          Inbox {stats.inbox_configured ? 'Configured' : 'Not configured'}
        </span>
        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium bg-blue-50 border-blue-200 text-blue-700">
          {stats.total} total events
        </span>
        <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium ${
          stats.by_telegram_status.PENDING > 0
            ? 'bg-yellow-50 border-yellow-200 text-yellow-700'
            : 'bg-gray-50 border-gray-200 text-gray-500'
        }`}>
          📡 Telegram — {stats.by_telegram_status.SENT} sent · {stats.by_telegram_status.PENDING} pending
          {stats.by_telegram_status.FAILED > 0 && (
            <span className="text-red-600 font-semibold"> · {stats.by_telegram_status.FAILED} failed</span>
          )}
        </span>
      </div>

      {stats.by_mail_status.FAILED > 0 && stats.recent_failed.length > 0 && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
          <p className="text-xs font-semibold text-red-800 mb-2 uppercase tracking-wide">Recent Failures</p>
          <div className="space-y-1.5">
            {stats.recent_failed.map((f) => (
              <div key={f.id} className="text-xs text-red-700 flex flex-wrap gap-2">
                <span className="font-medium">#{f.id}</span>
                <span className="text-red-500">{f.event_type}</span>
                <span className="truncate max-w-xs">{f.subject}</span>
                {f.last_error && (
                  <span className="text-red-400 italic truncate max-w-xs">— {f.last_error}</span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Filter bar ────────────────────────────────────────────────────────────────

const STATUS_OPTIONS = ['', 'PENDING', 'SENT', 'FAILED', 'ACKNOWLEDGED']
const EVENT_TYPE_OPTIONS = [
  '',
  'INQUIRY.RECEIVED',
  'CLIENT.ONBOARDING_RECEIVED',
  'BOOKING.REQUEST_RECEIVED',
  'BOOKING.CONFIRMED',
  'BOOKING.MODIFICATION_REQUESTED',
  'BOOKING.CANCELLED',
  'SUPPORT.REQUEST_RECEIVED',
  'PAYMENT.ISSUE_REPORTED',
  'EXPENSE.LARGE_RECORDED',
  'TASK.CREATED',
  'SYSTEM.ERROR',
]

interface FilterBarProps {
  status: string
  eventType: string
  search: string
  onStatus: (v: string) => void
  onEventType: (v: string) => void
  onSearch: (v: string) => void
}

function FilterBar({ status, eventType, search, onStatus, onEventType, onSearch }: FilterBarProps) {
  return (
    <div className="flex flex-wrap gap-3 items-center mb-4">
      <select
        value={status}
        onChange={(e) => onStatus(e.target.value)}
        className="border border-gray-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
      >
        {STATUS_OPTIONS.map((s) => (
          <option key={s} value={s}>{s || 'All Statuses'}</option>
        ))}
      </select>

      <select
        value={eventType}
        onChange={(e) => onEventType(e.target.value)}
        className="border border-gray-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
      >
        {EVENT_TYPE_OPTIONS.map((t) => (
          <option key={t} value={t}>{t || 'All Event Types'}</option>
        ))}
      </select>

      <input
        type="search"
        value={search}
        onChange={(e) => onSearch(e.target.value)}
        placeholder="Search subject, summary, event…"
        className="border border-gray-300 rounded px-3 py-1.5 text-sm w-full sm:w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>
  )
}

// ── Queue table ───────────────────────────────────────────────────────────────

interface QueueTableProps {
  rows: OpsmailQueueEvent[]
  loading: boolean
  acknowledging: number | null
  onAcknowledge: (id: number) => void
}

function QueueTable({ rows, loading, acknowledging, onAcknowledge }: QueueTableProps) {
  if (loading) {
    return (
      <div className="rounded border border-gray-200 py-16 text-center text-gray-400 text-sm">
        Loading queue…
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <div className="rounded border border-gray-200 py-16 text-center text-gray-400 text-sm">
        No events found.
      </div>
    )
  }

  return (
    <div className="overflow-x-auto rounded border border-gray-200">
      <table className="min-w-full text-sm">
        <thead className="bg-gray-50 border-b border-gray-200">
          <tr>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600 whitespace-nowrap">Created</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600 whitespace-nowrap">Event Type</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600">Subject</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600 whitespace-nowrap">Origin</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600">Email</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600 whitespace-nowrap">📡 Telegram</th>
            <th className="px-4 py-2.5 text-center font-medium text-gray-600 whitespace-nowrap">Attempts</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600">Last Error</th>
            <th className="px-4 py-2.5 text-left font-medium text-gray-600">Action</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {rows.map((row) => (
            <tr key={row.id} className="hover:bg-gray-50">
              <td className="px-4 py-2.5 text-gray-500 whitespace-nowrap text-xs">
                <div>{fmt.date(row.created_at)}</div>
                {row.sent_at && (
                  <div className="text-gray-400 mt-0.5">Sent {fmt.date(row.sent_at)}</div>
                )}
              </td>
              <td className="px-4 py-2.5 whitespace-nowrap">
                <span className="font-mono text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">
                  {row.event_type}
                </span>
                {row.branch_name && (
                  <div className="text-xs text-gray-400 mt-0.5">{row.branch_name}</div>
                )}
              </td>
              <td className="px-4 py-2.5 max-w-xs">
                <div className="truncate text-gray-800" title={row.subject}>{row.subject}</div>
                {row.summary && (
                  <div className="text-xs text-gray-400 truncate mt-0.5" title={row.summary}>{row.summary}</div>
                )}
              </td>
              <td className="px-4 py-2.5 text-gray-500 whitespace-nowrap text-xs">
                <div>{row.origin_type || '—'}</div>
                {row.recipient_email && (
                  <div className="text-gray-400 truncate max-w-[10rem]" title={row.recipient_email}>
                    {row.recipient_email}
                  </div>
                )}
              </td>
              <td className="px-4 py-2.5 whitespace-nowrap">
                <StatusBadge status={row.mail_status} />
              </td>
              <td className="px-4 py-2.5 whitespace-nowrap">
                <TelegramBadge status={row.telegram_status} />
                {row.telegram_sent_at && (
                  <div className="text-xs text-gray-400 mt-0.5">{fmt.date(row.telegram_sent_at)}</div>
                )}
                {row.telegram_status !== 'SENT' && row.telegram_attempts > 0 && (
                  <div className="text-xs text-orange-500 mt-0.5">attempt {row.telegram_attempts}</div>
                )}
              </td>
              <td className="px-4 py-2.5 text-center text-gray-700">
                {row.mail_attempts}
              </td>
              <td className="px-4 py-2.5 max-w-[12rem]">
                {row.last_error ? (
                  <span className="text-xs text-red-600 italic truncate block" title={row.last_error}>
                    {row.last_error}
                  </span>
                ) : (
                  <span className="text-gray-300 text-xs">—</span>
                )}
              </td>
              <td className="px-4 py-2.5 whitespace-nowrap">
                {row.mail_status !== 'ACKNOWLEDGED' ? (
                  <button
                    onClick={() => onAcknowledge(row.id)}
                    disabled={acknowledging === row.id}
                    className="px-2.5 py-1 rounded text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 disabled:opacity-50 transition-colors"
                  >
                    {acknowledging === row.id ? 'Saving…' : 'Acknowledge'}
                  </button>
                ) : (
                  <span className="text-xs text-gray-400">Done</span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Pagination ─────────────────────────────────────────────────────────────────

function Pagination({
  page, totalPages, total, onPage,
}: {
  page: number; totalPages: number; total: number; onPage: (p: number) => void
}) {
  if (totalPages <= 1) return null
  return (
    <div className="flex items-center justify-between mt-4 text-sm text-gray-600">
      <span>{total} total events</span>
      <div className="flex gap-2">
        <button
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
          className="px-3 py-1.5 border border-gray-300 rounded disabled:opacity-40 hover:bg-gray-50"
        >
          Prev
        </button>
        <span className="px-2 py-1.5">{page} / {totalPages}</span>
        <button
          disabled={page >= totalPages}
          onClick={() => onPage(page + 1)}
          className="px-3 py-1.5 border border-gray-300 rounded disabled:opacity-40 hover:bg-gray-50"
        >
          Next
        </button>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function OpsmailQueue() {
  const [stats, setStats]             = useState<OpsmailStats | null>(null)
  const [rows, setRows]               = useState<OpsmailQueueEvent[]>([])
  const [total, setTotal]             = useState(0)
  const [totalPages, setTotalPages]   = useState(1)
  const [page, setPage]               = useState(1)
  const [status, setStatus]           = useState('')
  const [eventType, setEventType]     = useState('')
  const [search, setSearch]           = useState('')
  const [loading, setLoading]         = useState(true)
  const [statsLoading, setStatsLoading] = useState(true)
  const [err, setErr]                 = useState('')
  const [acknowledging, setAcknowledging] = useState<number | null>(null)
  const [ackMsg, setAckMsg]           = useState('')
  const debounce = useRef<number | undefined>()

  if (!canAccess()) {
    return (
      <div className="card max-w-md mx-auto mt-12 text-center">
        <p className="text-gray-500 text-sm">You do not have permission to view this page.</p>
      </div>
    )
  }

  const loadStats = useCallback(() => {
    setStatsLoading(true)
    opsmailApi.getStats()
      .then(setStats)
      .catch(() => {})
      .finally(() => setStatsLoading(false))
  }, [])

  const loadQueue = useCallback(() => {
    setLoading(true)
    setErr('')
    opsmailApi.getQueue({ page, per_page: 50, status, event_type: eventType, search })
      .then((res) => {
        setRows(res.data)
        setTotal(res.total)
        setTotalPages(res.total_pages)
      })
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load queue.'))
      .finally(() => setLoading(false))
  }, [page, status, eventType, search])

  useEffect(() => { loadStats() }, [loadStats])

  useEffect(() => { setPage(1) }, [status, eventType, search])

  useEffect(() => {
    clearTimeout(debounce.current)
    debounce.current = window.setTimeout(loadQueue, search ? 300 : 0)
    return () => clearTimeout(debounce.current)
  }, [loadQueue])

  const handleAcknowledge = async (id: number) => {
    setAcknowledging(id)
    setAckMsg('')
    try {
      await opsmailApi.acknowledge(id)
      setRows((prev) => prev.map((r) => r.id === id ? { ...r, mail_status: 'ACKNOWLEDGED' as const } : r))
      setAckMsg(`Event #${id} acknowledged.`)
      loadStats()
    } catch (e) {
      setAckMsg(e instanceof ApiError ? e.message : 'Acknowledge failed.')
    } finally {
      setAcknowledging(null)
    }
  }

  return (
    <div className="max-w-6xl">
      <div className="flex items-center justify-between mb-5 gap-4">
        <div>
          <h1 className="page-title mb-0">OPSMAIL Queue</h1>
          <p className="text-sm text-gray-500 mt-0.5">Operational intelligence event log</p>
        </div>
        <button
          onClick={() => { loadQueue(); loadStats() }}
          className="btn-secondary text-sm"
        >
          ↻ Refresh
        </button>
      </div>

      {/* Scheduler health + external cron */}
      <CronHealthPanel />

      {/* Stats */}
      {!statsLoading && stats && <StatsBar stats={stats} />}
      {statsLoading && (
        <div className="h-24 rounded-lg border border-gray-200 bg-gray-50 animate-pulse mb-6" />
      )}

      {/* Telegram panel */}
      {!statsLoading && stats && (
        <TelegramPanel
          stats={stats}
          onDone={() => { loadQueue(); loadStats() }}
        />
      )}
      {statsLoading && (
        <div className="h-32 rounded-lg border border-gray-200 bg-gray-50 animate-pulse mb-6" />
      )}

      {/* Ack feedback */}
      {ackMsg && (
        <div className="mb-3 px-4 py-2.5 rounded-lg border text-sm bg-blue-50 border-blue-200 text-blue-800">
          {ackMsg}
        </div>
      )}

      {/* Error */}
      {err && (
        <div className="mb-3 px-4 py-2.5 rounded-lg border text-sm bg-red-50 border-red-200 text-red-700">
          {err}
        </div>
      )}

      {/* Filters */}
      <FilterBar
        status={status}
        eventType={eventType}
        search={search}
        onStatus={(v) => setStatus(v)}
        onEventType={(v) => setEventType(v)}
        onSearch={(v) => setSearch(v)}
      />

      {/* Table */}
      <QueueTable
        rows={rows}
        loading={loading}
        acknowledging={acknowledging}
        onAcknowledge={handleAcknowledge}
      />

      <Pagination page={page} totalPages={totalPages} total={total} onPage={setPage} />
    </div>
  )
}
