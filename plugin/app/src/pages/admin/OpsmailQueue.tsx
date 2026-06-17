import { useState, useEffect, useCallback, useRef } from 'react'
import { opsmailApi, type OpsmailQueueEvent, type OpsmailStats } from '../../api/opsmail'
import { ApiError, fmt } from '../../api/client'

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
    { label: 'Pending',      value: stats.by_status.PENDING,      cls: 'text-yellow-700 bg-yellow-50 border-yellow-200' },
    { label: 'Sent',         value: stats.by_status.SENT,         cls: 'text-green-700 bg-green-50 border-green-200' },
    { label: 'Failed',       value: stats.by_status.FAILED,       cls: 'text-red-700 bg-red-50 border-red-200' },
    { label: 'Acknowledged', value: stats.by_status.ACKNOWLEDGED, cls: 'text-gray-600 bg-gray-50 border-gray-200' },
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
      </div>

      {stats.by_status.FAILED > 0 && stats.recent_failed.length > 0 && (
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
  'booking_confirmed',
  'inquiry_received',
  'onboarding_received',
  'task_created',
  'large_expense',
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
            <th className="px-4 py-2.5 text-left font-medium text-gray-600">Status</th>
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
                <StatusBadge status={row.status} />
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
                {row.status !== 'ACKNOWLEDGED' ? (
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
      setRows((prev) => prev.map((r) => r.id === id ? { ...r, status: 'ACKNOWLEDGED' } : r))
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

      {/* Stats */}
      {!statsLoading && stats && <StatsBar stats={stats} />}
      {statsLoading && (
        <div className="h-24 rounded-lg border border-gray-200 bg-gray-50 animate-pulse mb-6" />
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
