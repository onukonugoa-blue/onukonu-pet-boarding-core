import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { reportsApi } from '../api/reports'
import type { ReportData } from '../api/reports'
import { useBranchStore } from '../store/branch'
import { fmt } from '../api/client'

// ── Tiny SVG chart helpers ────────────────────────────────────────────────────

function LineChart({ data, keys, colors, height = 160 }: {
  data: Record<string, number>[]
  keys: string[]
  colors: string[]
  height?: number
}) {
  if (!data.length) return <Empty />
  const W = 600
  const H = height
  const PAD = { t: 10, r: 10, b: 28, l: 52 }
  const iW = W - PAD.l - PAD.r
  const iH = H - PAD.t - PAD.b

  const allVals = data.flatMap(d => keys.map(k => Number(d[k] ?? 0)))
  const maxV = Math.max(...allVals, 1)

  const xOf = (i: number) => PAD.l + (i / (data.length - 1 || 1)) * iW
  const yOf = (v: number) => PAD.t + iH - (v / maxV) * iH

  const yTicks = 4
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ height }}>
      {Array.from({ length: yTicks + 1 }, (_, i) => {
        const v = (maxV / yTicks) * i
        const y = yOf(v)
        return (
          <g key={i}>
            <line x1={PAD.l} x2={W - PAD.r} y1={y} y2={y} stroke="#e5e7eb" strokeWidth="1" />
            <text x={PAD.l - 4} y={y + 4} textAnchor="end" fontSize="9" fill="#9ca3af">
              {v >= 1000 ? `₹${Math.round(v / 1000)}k` : `₹${Math.round(v)}`}
            </text>
          </g>
        )
      })}
      {keys.map((key, ki) => {
        const pts = data.map((d, i) => `${xOf(i)},${yOf(Number(d[key] ?? 0))}`).join(' ')
        return (
          <polyline key={key} points={pts} fill="none"
            stroke={colors[ki]} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
        )
      })}
      {data.map((d, i) => {
        if (data.length > 30 && i % 7 !== 0) return null
        const label = String(d.day ?? d.week ?? '').slice(5)
        return (
          <text key={i} x={xOf(i)} y={H - 4} textAnchor="middle" fontSize="8" fill="#6b7280">
            {label}
          </text>
        )
      })}
    </svg>
  )
}

function BarChart({ data, labelKey, valueKey, color = '#3b82f6', height = 160 }: {
  data: Record<string, number | string>[]
  labelKey: string
  valueKey: string
  color?: string
  height?: number
}) {
  if (!data.length) return <Empty />
  const W = 600
  const H = height
  const PAD = { t: 10, r: 10, b: 36, l: 52 }
  const iW = W - PAD.l - PAD.r
  const iH = H - PAD.t - PAD.b

  const vals = data.map(d => Number(d[valueKey] ?? 0))
  const maxV = Math.max(...vals, 1)
  const barW = Math.min(40, (iW / data.length) * 0.6)
  const gap   = iW / data.length
  const yTicks = 4

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ height }}>
      {Array.from({ length: yTicks + 1 }, (_, i) => {
        const v = (maxV / yTicks) * i
        const y = PAD.t + iH - (v / maxV) * iH
        return (
          <g key={i}>
            <line x1={PAD.l} x2={W - PAD.r} y1={y} y2={y} stroke="#e5e7eb" strokeWidth="1" />
            <text x={PAD.l - 4} y={y + 4} textAnchor="end" fontSize="9" fill="#9ca3af">
              {v >= 1000 ? `₹${Math.round(v / 1000)}k` : `₹${Math.round(v)}`}
            </text>
          </g>
        )
      })}
      {data.map((d, i) => {
        const v = Number(d[valueKey] ?? 0)
        const x = PAD.l + gap * i + gap / 2 - barW / 2
        const bH = (v / maxV) * iH
        const y = PAD.t + iH - bH
        const label = String(d[labelKey] ?? '').slice(0, 12)
        return (
          <g key={i}>
            <rect x={x} y={y} width={barW} height={bH} fill={color} rx="2" />
            <text x={x + barW / 2} y={H - 4} textAnchor="middle" fontSize="8" fill="#6b7280">
              {label}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

function DonutChart({ data, height = 160 }: {
  data: { label: string; value: number }[]
  height?: number
}) {
  if (!data.length) return <Empty />
  const COLORS = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16']
  const total = data.reduce((s, d) => s + d.value, 0) || 1
  const R = 55; const CX = 80; const CY = 80
  let angle = -Math.PI / 2
  const slices = data.map((d, i) => {
    const slice = (d.value / total) * 2 * Math.PI
    const x1 = CX + R * Math.cos(angle)
    const y1 = CY + R * Math.sin(angle)
    angle += slice
    const x2 = CX + R * Math.cos(angle)
    const y2 = CY + R * Math.sin(angle)
    const large = slice > Math.PI ? 1 : 0
    return { path: `M${CX},${CY} L${x1},${y1} A${R},${R} 0 ${large},1 ${x2},${y2} Z`, color: COLORS[i % COLORS.length], ...d }
  })

  return (
    <svg viewBox="0 0 300 160" className="w-full" style={{ height }}>
      {slices.map((s, i) => (
        <path key={i} d={s.path} fill={s.color} stroke="white" strokeWidth="1.5" />
      ))}
      <circle cx={CX} cy={CY} r={28} fill="white" />
      <text x={CX} y={CY - 4} textAnchor="middle" fontSize="9" fill="#374151" fontWeight="600">Total</text>
      <text x={CX} y={CY + 9} textAnchor="middle" fontSize="8" fill="#6b7280">
        {total >= 1000 ? `₹${(total/1000).toFixed(1)}k` : `₹${Math.round(total)}`}
      </text>
      <g>
        {slices.map((s, i) => {
          const lx = 175
          const ly = 18 + i * 16
          if (ly > 155) return null
          return (
            <g key={i}>
              <rect x={lx} y={ly - 7} width={10} height={10} fill={s.color} rx="2" />
              <text x={lx + 14} y={ly + 2} fontSize="9" fill="#374151">
                {s.label.slice(0, 14)} ({((s.value / total) * 100).toFixed(0)}%)
              </text>
            </g>
          )
        })}
      </g>
    </svg>
  )
}

function Empty() {
  return <div className="flex items-center justify-center py-8 text-gray-400 text-sm">No data for this period</div>
}

// ── Date helpers ──────────────────────────────────────────────────────────────

function today() {
  return new Date().toISOString().slice(0, 10)
}
function monthStart() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function Reports() {
  const [from, setFrom] = useState(monthStart())
  const [to, setTo]     = useState(today())
  const [data, setData] = useState<ReportData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const activeBranchId = useBranchStore((s) => s.activeBranchId)

  const load = () => {
    setLoading(true)
    setError('')
    reportsApi.get({ from, to, branch_id: activeBranchId || undefined })
      .then(setData)
      .catch((e) => setError(e.message ?? 'Failed to load report'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [activeBranchId])

  const preset = (label: string) => {
    const now = new Date()
    if (label === 'This month') {
      setFrom(`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`)
      setTo(today())
    } else if (label === 'Last month') {
      const lm = new Date(now.getFullYear(), now.getMonth() - 1, 1)
      const le = new Date(now.getFullYear(), now.getMonth(), 0)
      setFrom(lm.toISOString().slice(0,10))
      setTo(le.toISOString().slice(0,10))
    } else if (label === 'Last 90 days') {
      const d = new Date(); d.setDate(d.getDate() - 90)
      setFrom(d.toISOString().slice(0,10))
      setTo(today())
    } else if (label === 'This year') {
      setFrom(`${now.getFullYear()}-01-01`)
      setTo(today())
    }
  }

  const s = data?.summary

  return (
    <div className="space-y-5">
      <div className="page-header">
        <h1 className="page-title">Reports &amp; Analytics</h1>
      </div>

      {/* Filters */}
      <div className="card">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" value={from} onChange={e => setFrom(e.target.value)}
              className="input text-sm py-1.5" />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" value={to} onChange={e => setTo(e.target.value)}
              className="input text-sm py-1.5" />
          </div>
          <div className="flex gap-1.5 flex-wrap">
            {['This month','Last month','Last 90 days','This year'].map(p => (
              <button key={p} onClick={() => preset(p)}
                className="px-2.5 py-1.5 text-xs rounded border border-gray-300 hover:bg-gray-50 text-gray-600">
                {p}
              </button>
            ))}
          </div>
          <button onClick={load} disabled={loading}
            className="btn btn-primary text-sm py-1.5 px-4 ml-auto">
            {loading ? 'Loading…' : 'Run Report'}
          </button>
        </div>
        {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
      </div>

      {loading && (
        <div className="flex items-center justify-center py-20 text-gray-400">Generating report…</div>
      )}

      {!loading && data && (
        <>
          {/* Summary KPIs */}
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
            {[
              { label: 'Total Revenue',     value: fmt.inr(s!.total_revenue),     color: 'text-green-600' },
              { label: 'Total Expenses',    value: fmt.inr(s!.total_expenses),    color: 'text-red-500'   },
              { label: 'Net Profit',        value: fmt.inr(s!.net_profit),        color: s!.net_profit >= 0 ? 'text-blue-600' : 'text-red-600' },
              { label: 'Bookings',          value: s!.total_bookings,             color: 'text-gray-900'  },
              { label: 'Outstanding',       value: fmt.inr(s!.total_outstanding), color: s!.total_outstanding > 0 ? 'text-orange-500' : 'text-gray-900' },
            ].map(k => (
              <div key={k.label} className="kpi-card">
                <div className={`kpi-value ${k.color}`}>{k.value}</div>
                <div className="kpi-label">{k.label}</div>
              </div>
            ))}
          </div>

          {/* Revenue vs Expenses trend */}
          <div className="card">
            <div className="card-header">
              <h2 className="font-semibold text-gray-900">Revenue vs Expenses — Daily</h2>
              <div className="flex gap-4 text-xs text-gray-500 mt-1">
                <span className="flex items-center gap-1"><span className="inline-block w-4 h-0.5 bg-blue-500" />Revenue</span>
                <span className="flex items-center gap-1"><span className="inline-block w-4 h-0.5 bg-red-400" />Expenses</span>
              </div>
            </div>
            <LineChart
              data={data.revenue_by_day as unknown as Record<string, number>[]}
              keys={['revenue','expense']}
              colors={['#3b82f6','#f87171']}
              height={180}
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Expenses by category */}
            <div className="card">
              <div className="card-header">
                <h2 className="font-semibold text-gray-900">Expenses by Category</h2>
              </div>
              <DonutChart
                data={data.expenses_by_category.map(e => ({ label: e.category, value: e.total }))}
                height={160}
              />
              <div className="mt-2 divide-y divide-gray-100">
                {data.expenses_by_category.map(e => (
                  <div key={e.category} className="flex justify-between py-1.5 text-sm">
                    <span className="text-gray-700">{e.category}</span>
                    <span className="font-medium text-gray-900">{fmt.inr(e.total)}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Revenue by branch */}
            <div className="card">
              <div className="card-header">
                <h2 className="font-semibold text-gray-900">Revenue by Branch</h2>
              </div>
              <BarChart
                data={data.revenue_by_branch as unknown as Record<string, number | string>[]}
                labelKey="branch"
                valueKey="revenue"
                color="#10b981"
                height={160}
              />
              <div className="mt-2 divide-y divide-gray-100">
                {data.revenue_by_branch.map(b => (
                  <div key={b.branch} className="flex justify-between py-1.5 text-sm">
                    <span className="text-gray-700">{b.branch}</span>
                    <div className="text-right">
                      <span className="font-medium text-gray-900">{fmt.inr(b.revenue)}</span>
                      {b.outstanding > 0 && (
                        <span className="ml-2 text-xs text-orange-500">({fmt.inr(b.outstanding)} due)</span>
                      )}
                    </div>
                  </div>
                ))}
                {!data.revenue_by_branch.length && <Empty />}
              </div>
            </div>
          </div>

          {/* Occupancy by week */}
          {data.occupancy_by_week.length > 0 && (
            <div className="card">
              <div className="card-header">
                <h2 className="font-semibold text-gray-900">Occupancy Rate — Weekly (%)</h2>
              </div>
              <BarChart
                data={data.occupancy_by_week as unknown as Record<string, number | string>[]}
                labelKey="week"
                valueKey="rate"
                color="#8b5cf6"
                height={140}
              />
            </div>
          )}

          {/* Top clients */}
          <div className="card">
            <div className="card-header flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">Top Clients by Revenue</h2>
              <Link to="/clients" className="text-sm text-blue-600 hover:underline">All clients →</Link>
            </div>
            {data.top_clients.length === 0 ? <Empty /> : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-xs text-gray-500 uppercase">
                      <th className="py-2 text-left font-medium">#</th>
                      <th className="py-2 text-left font-medium">Client</th>
                      <th className="py-2 text-right font-medium">Bookings</th>
                      <th className="py-2 text-right font-medium">Revenue</th>
                      <th className="py-2 text-right font-medium">Share</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {data.top_clients.map((c, i) => {
                      const share = s!.total_revenue > 0
                        ? ((c.revenue / s!.total_revenue) * 100).toFixed(1)
                        : '0.0'
                      return (
                        <tr key={c.client_id} className="hover:bg-gray-50">
                          <td className="py-2 text-gray-400">{i + 1}</td>
                          <td className="py-2">
                            <Link to={`/clients/${c.client_id}`}
                              className="font-medium text-blue-600 hover:underline">{c.name}</Link>
                          </td>
                          <td className="py-2 text-right text-gray-600">{c.bookings}</td>
                          <td className="py-2 text-right font-medium text-gray-900">{fmt.inr(c.revenue)}</td>
                          <td className="py-2 text-right">
                            <div className="flex items-center justify-end gap-2">
                              <div className="w-16 bg-gray-200 rounded-full h-1.5">
                                <div className="bg-blue-500 h-1.5 rounded-full"
                                  style={{ width: `${share}%` }} />
                              </div>
                              <span className="text-gray-500 text-xs w-8 text-right">{share}%</span>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
