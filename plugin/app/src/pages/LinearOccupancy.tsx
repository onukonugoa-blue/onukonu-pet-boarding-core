import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { kennelsApi } from '../api/kennels'
import type { Kennel } from '../api/kennels'
import { bookingsApi } from '../api/bookings'
import type { BookingStay } from '../api/bookings'
import { useBranchStore } from '../store/branch'

const DAY_WIDTH = 80
const KENNEL_COL = 140

const STATUS_BAR_COLORS: Record<string, string> = {
  Active:    'bg-green-500 text-white',
  Upcoming:  'bg-blue-500 text-white',
  Completed: 'bg-gray-400 text-white',
}

const KENNEL_STATUS_CHIP: Record<string, string> = {
  Maintenance: 'bg-yellow-100 text-yellow-700 border border-yellow-200',
  Blocked:     'bg-red-100 text-red-700 border border-red-200',
}

function daysBetween(from: string, to: string): number {
  const a = new Date(from + 'T00:00:00')
  const b = new Date(to   + 'T00:00:00')
  return Math.round((b.getTime() - a.getTime()) / 86400000)
}

function formatDayHeader(iso: string): { dow: string; date: string } {
  const d = new Date(iso + 'T00:00:00')
  return {
    dow:  d.toLocaleDateString('en-IN', { weekday: 'short' }),
    date: d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }),
  }
}

function addDays(iso: string, n: number): string {
  const d = new Date(iso + 'T00:00:00')
  d.setDate(d.getDate() + n)
  return d.toISOString().slice(0, 10)
}

interface StayBar {
  stay: BookingStay
  startIdx: number
  endIdx: number
}

export default function LinearOccupancy() {
  const today = new Date().toISOString().slice(0, 10)

  const [kennels, setKennels]   = useState<Kennel[]>([])
  const [days, setDays]         = useState<string[]>([])
  const [stays, setStays]       = useState<BookingStay[]>([])
  const [loading, setLoading]   = useState(true)
  const [error, setError]       = useState('')
  const [fromDate, setFromDate] = useState(today)
  const [toDate, setToDate]     = useState(addDays(today, 6))
  const [pendingFrom, setPendingFrom] = useState(today)
  const [pendingTo, setPendingTo]     = useState(addDays(today, 6))

  const selectedBranch = useBranchStore((s) => s.activeBranchId) || null

  const load = async (from: string, to: string, branchId: number | null) => {
    setLoading(true)
    setError('')
    try {
      const [ks, board] = await Promise.all([
        kennelsApi.list(branchId ?? undefined, true),
        bookingsApi.kennelBoard({
          from,
          to,
          ...(branchId ? { branch_id: branchId } : {}),
        }),
      ])
      setKennels(ks)
      setDays(board.days ?? [])
      setStays((board.stays ?? []).filter((s: BookingStay) => s.kennel_id))
    } catch (e: any) {
      setError(e.message ?? 'Failed to load data')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load(fromDate, toDate, selectedBranch)
  }, [selectedBranch])

  const handleApply = () => {
    if (!pendingFrom || !pendingTo || pendingFrom > pendingTo) return
    setFromDate(pendingFrom)
    setToDate(pendingTo)
    load(pendingFrom, pendingTo, selectedBranch)
  }

  const staysByKennel = (kennelId: number): StayBar[] => {
    return stays
      .filter((s) => s.kennel_id === kennelId)
      .map((s) => {
        const startIdx = Math.max(0, daysBetween(fromDate, s.check_in_date))
        const endIdx   = Math.min(days.length - 1, daysBetween(fromDate, s.check_out_date))
        return { stay: s, startIdx, endIdx }
      })
      .filter((b) => b.endIdx >= b.startIdx)
  }

  const todayIdx = daysBetween(fromDate, today)
  const totalWidth = days.length * DAY_WIDTH

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Kennel Board</h1>
          <p className="text-sm text-gray-500 mt-0.5">Occupancy view — stay assignments over a date range</p>
        </div>
      </div>

      {/* Tab nav */}
      <div className="flex gap-1 mb-5 border-b border-gray-200">
        <Link
          to="/kennel"
          className="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 transition-colors"
        >
          Board
        </Link>
        <span className="px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600">
          Timeline
        </span>
      </div>

      {/* Date range controls */}
      <div className="flex flex-wrap items-center gap-3 mb-5">
        <div className="flex items-center gap-2">
          <label className="text-sm text-gray-600 font-medium">From</label>
          <input
            type="date"
            className="form-input text-sm py-1"
            value={pendingFrom}
            onChange={(e) => setPendingFrom(e.target.value)}
          />
        </div>
        <div className="flex items-center gap-2">
          <label className="text-sm text-gray-600 font-medium">To</label>
          <input
            type="date"
            className="form-input text-sm py-1"
            value={pendingTo}
            onChange={(e) => setPendingTo(e.target.value)}
          />
        </div>
        <button onClick={handleApply} className="btn-primary text-sm py-1 px-4">
          Apply
        </button>
        {!loading && (
          <span className="text-xs text-gray-400">{days.length} days · {kennels.length} kennels</span>
        )}
      </div>

      {error && <div className="alert-error mb-4">{error}</div>}

      {loading ? (
        <div className="flex items-center justify-center py-20 text-gray-400">Loading timeline…</div>
      ) : kennels.length === 0 ? (
        <div className="empty-state">
          <span>🏠</span>
          <p>No kennels configured yet.</p>
        </div>
      ) : (
        <div className="card overflow-hidden p-0">
          <div className="overflow-x-auto">
            <div style={{ minWidth: KENNEL_COL + totalWidth + 2 }}>

              {/* Header row */}
              <div className="flex border-b border-gray-200 bg-gray-50">
                <div
                  className="shrink-0 sticky left-0 z-10 bg-gray-50 border-r border-gray-200 px-3 py-2"
                  style={{ width: KENNEL_COL }}
                />
                {days.map((day, i) => {
                  const { dow, date } = formatDayHeader(day)
                  const isToday = day === today
                  return (
                    <div
                      key={day}
                      className={`shrink-0 text-center py-2 border-r border-gray-100 ${isToday ? 'bg-blue-50' : ''}`}
                      style={{ width: DAY_WIDTH }}
                    >
                      <div className={`text-xs font-semibold ${isToday ? 'text-blue-600' : 'text-gray-500'}`}>{dow}</div>
                      <div className={`text-xs ${isToday ? 'text-blue-700 font-bold' : 'text-gray-400'}`}>{date}</div>
                    </div>
                  )
                })}
              </div>

              {/* Kennel rows */}
              {kennels.map((k) => {
                const bars   = staysByKennel(k.id)
                const isAdminStatus = k.status === 'Maintenance' || k.status === 'Blocked'

                return (
                  <div key={k.id} className="flex border-b border-gray-100 hover:bg-gray-50 group" style={{ minHeight: 52 }}>
                    {/* Kennel info — sticky */}
                    <div
                      className="shrink-0 sticky left-0 z-10 bg-white group-hover:bg-gray-50 border-r border-gray-200 px-3 flex flex-col justify-center"
                      style={{ width: KENNEL_COL }}
                    >
                      <span className="font-mono font-bold text-gray-900 text-sm leading-tight">{k.code}</span>
                      <span className="text-xs text-gray-400 truncate leading-tight">{k.name}</span>
                      {k.assigned_staff_name && (
                        <span className="text-xs text-gray-400 truncate leading-tight">👤 {k.assigned_staff_name}</span>
                      )}
                    </div>

                    {/* Timeline area */}
                    <div className="relative flex-1" style={{ width: totalWidth, minHeight: 52 }}>
                      {/* Day column backgrounds / today highlight */}
                      {days.map((day, i) => (
                        <div
                          key={day}
                          className={`absolute top-0 bottom-0 border-r border-gray-100 ${day === today ? 'bg-blue-50/60' : ''}`}
                          style={{ left: i * DAY_WIDTH, width: DAY_WIDTH }}
                        />
                      ))}

                      {/* Admin status overlay */}
                      {isAdminStatus && (
                        <div className="absolute inset-0 flex items-center px-3">
                          <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${KENNEL_STATUS_CHIP[k.status]}`}>
                            {k.status}
                          </span>
                        </div>
                      )}

                      {/* Stay bars */}
                      {!isAdminStatus && bars.map(({ stay, startIdx, endIdx }) => {
                        const barLeft  = startIdx * DAY_WIDTH + 2
                        const barWidth = (endIdx - startIdx + 1) * DAY_WIDTH - 4
                        const colorClass = STATUS_BAR_COLORS[stay.status] ?? 'bg-gray-400 text-white'
                        return (
                          <Link
                            key={stay.id}
                            to={`/bookings/${stay.booking_id}`}
                            title={`${stay.pet_name} · ${stay.client_name} · ${stay.check_in_date} → ${stay.check_out_date}`}
                            className={`absolute top-2 bottom-2 rounded flex items-center px-2 overflow-hidden ${colorClass} hover:opacity-90 transition-opacity`}
                            style={{ left: barLeft, width: barWidth }}
                          >
                            <span className="text-xs font-medium truncate whitespace-nowrap">
                              {stay.pet_name}
                            </span>
                          </Link>
                        )
                      })}

                      {/* Available indicator when no stays and not admin status */}
                      {!isAdminStatus && bars.length === 0 && (
                        <div className="absolute inset-0 flex items-center px-3">
                          <span className="text-xs text-green-500 font-medium">Free</span>
                        </div>
                      )}
                    </div>
                  </div>
                )
              })}

            </div>
          </div>
        </div>
      )}
    </div>
  )
}
