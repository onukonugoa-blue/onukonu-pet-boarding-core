import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { bookingsApi } from '../api/bookings'
import type { KennelBoard, BookingStay } from '../api/bookings'
import { useBranchStore } from '../store/branch'

export default function OccupancyBoard() {
  const [board, setBoard] = useState<KennelBoard | null>(null)
  const [loading, setLoading] = useState(true)
  const [from, setFrom] = useState(() => new Date().toISOString().slice(0, 10))
  const [to, setTo] = useState(() => {
    const d = new Date(); d.setDate(d.getDate() + 13); return d.toISOString().slice(0, 10)
  })
  const activeBranchId = useBranchStore((s) => s.activeBranchId)

  const load = () => {
    setLoading(true)
    const params: Record<string, unknown> = { from, to }
    if (activeBranchId) params.branch_id = activeBranchId
    bookingsApi.kennelBoard(params)
      .then(setBoard)
      .catch(console.error)
      .finally(() => setLoading(false))
  }

  useEffect(load, [activeBranchId])

  // Group stays by kennel/pet
  const staysByKennel = (board?.stays ?? []).reduce<Record<string, BookingStay[]>>((acc, s) => {
    const key = s.kennel || `Pet:${s.pet_name ?? s.pet_id}`
    acc[key] = acc[key] ?? []
    acc[key].push(s)
    return acc
  }, {})

  const kennels = Object.keys(staysByKennel).sort()
  const days = board?.days ?? []

  const isOccupied = (stay: BookingStay, day: string) =>
    day >= stay.check_in_date && day <= stay.check_out_date

  const statusColor: Record<string, string> = {
    Active: 'bg-green-200 text-green-900 border-green-300',
    Upcoming: 'bg-blue-200 text-blue-900 border-blue-300',
    Completed: 'bg-gray-100 text-gray-500 border-gray-200',
    'No show': 'bg-red-100 text-red-700 border-red-200',
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Kennel / Occupancy Board</h1>
      </div>

      <div className="card mb-4">
        <div className="flex flex-wrap gap-2 items-end">
          <div className="form-group mb-0">
            <label className="form-label">From</label>
            <input className="form-input w-36" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="form-group mb-0">
            <label className="form-label">To</label>
            <input className="form-input w-36" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <button onClick={load} className="btn-primary">Load</button>
        </div>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16 text-gray-400">Loading board…</div>
      ) : !board || days.length === 0 ? (
        <div className="empty-state"><span>🏠</span><p>No stays in this date range</p></div>
      ) : (
        <div className="card overflow-x-auto">
          <table className="border-collapse text-xs min-w-max">
            <thead>
              <tr>
                <th className="sticky left-0 bg-white border border-gray-200 px-2 py-1 text-left text-xs font-semibold text-gray-600 min-w-[100px] z-10">Kennel / Pet</th>
                {days.map((d) => {
                  const dt = new Date(d)
                  const isToday = d === new Date().toISOString().slice(0, 10)
                  return (
                    <th key={d} className={`border border-gray-200 px-1 py-1 text-center font-normal min-w-[36px] ${isToday ? 'bg-yellow-50 font-bold' : 'bg-gray-50'}`}>
                      <div>{dt.getDate()}</div>
                      <div className="text-gray-400">{dt.toLocaleDateString('en',{weekday:'narrow'})}</div>
                    </th>
                  )
                })}
              </tr>
            </thead>
            <tbody>
              {kennels.map((kennel) => (
                <tr key={kennel}>
                  <td className="sticky left-0 bg-white border border-gray-200 px-2 py-1 font-medium text-gray-700 z-10">{kennel}</td>
                  {days.map((day) => {
                    const occupyingStay = staysByKennel[kennel].find((s) => isOccupied(s, day))
                    if (occupyingStay) {
                      const isStart = day === occupyingStay.check_in_date
                      const isEnd   = day === occupyingStay.check_out_date
                      return (
                        <td key={day} className="border border-gray-200 p-0">
                          <Link
                            to={`/bookings/${occupyingStay.booking_id}`}
                            className={`block h-7 px-1 leading-7 ${statusColor[occupyingStay.status] ?? 'bg-gray-200'} ${isStart ? 'rounded-l-sm' : ''} ${isEnd ? 'rounded-r-sm' : ''} truncate`}
                            title={`${occupyingStay.pet_name} (${occupyingStay.status})`}
                          >
                            {isStart ? occupyingStay.pet_name?.split(' ')[0] : ''}
                          </Link>
                        </td>
                      )
                    }
                    return <td key={day} className="border border-gray-200 h-7" />
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {board && (
        <div className="flex gap-4 mt-3 text-xs text-gray-500">
          {Object.entries(statusColor).map(([s, c]) => (
            <span key={s} className="flex items-center gap-1">
              <span className={`inline-block w-3 h-3 rounded-sm border ${c}`}></span>{s}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}
