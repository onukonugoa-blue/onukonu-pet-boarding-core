import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { kennelsApi } from '../api/kennels'
import type { Kennel } from '../api/kennels'
import { branchesApi } from '../api/branches'
import type { Branch } from '../store/branch'
import { bookingsApi } from '../api/bookings'
import type { BookingStay } from '../api/bookings'
import { useBranchStore } from '../store/branch'

interface OccupiedInfo {
  stay_id: number
  booking_id: number
  pet_name: string
  pet_type: string
  client_name: string
  check_in_date: string
  check_out_date: string
  status: string
}

const STATUS_ORDER = ['Available', 'Occupied', 'Maintenance', 'Blocked'] as const

const STATUS_STYLES: Record<string, { section: string; badge: string; dot: string }> = {
  Available:   { section: 'border-green-200 bg-green-50',  badge: 'bg-green-100 text-green-800 border-green-200',  dot: 'bg-green-500' },
  Occupied:    { section: 'border-blue-200 bg-blue-50',    badge: 'bg-blue-100 text-blue-800 border-blue-200',     dot: 'bg-blue-500' },
  Maintenance: { section: 'border-yellow-200 bg-yellow-50',badge: 'bg-yellow-100 text-yellow-800 border-yellow-200',dot: 'bg-yellow-500' },
  Blocked:     { section: 'border-red-200 bg-red-50',      badge: 'bg-red-100 text-red-800 border-red-200',        dot: 'bg-red-500' },
}

export default function OccupancyBoard() {
  const [kennels, setKennels]           = useState<Kennel[]>([])
  const [occupancy, setOccupancy]       = useState<Record<number, OccupiedInfo>>({})
  const [unassigned, setUnassigned]     = useState<BookingStay[]>([])
  const [branches, setBranches]         = useState<Branch[]>([])
  const [loading, setLoading]           = useState(true)
  const [selectedBranch, setSelectedBranch] = useState<number | null>(null)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)

  const today = new Date().toISOString().slice(0, 10)

  const load = async (branchId: number | null) => {
    setLoading(true)
    try {
      const [ks, bs, board] = await Promise.all([
        kennelsApi.list(branchId ?? undefined, true),
        branchesApi.list(),
        bookingsApi.kennelBoard({ from: today, to: today, ...(branchId ? { branch_id: branchId } : {}) }),
      ])
      setKennels(ks)
      setBranches(bs)

      // Build kennel_id → stay occupancy map from today's active/upcoming stays
      const occ: Record<number, OccupiedInfo> = {}
      const unasgn: BookingStay[] = []
      ;(board.stays ?? []).forEach((s: BookingStay) => {
        if (s.status === 'Completed' || s.status === 'No show') return
        if (s.kennel_id) {
          occ[s.kennel_id] = {
            stay_id:       s.id,
            booking_id:    s.booking_id,
            pet_name:      s.pet_name ?? '',
            pet_type:      s.pet_type ?? '',
            client_name:   s.client_name ?? '',
            check_in_date: s.check_in_date,
            check_out_date:s.check_out_date,
            status:        s.status,
          }
        } else {
          unasgn.push(s)
        }
      })
      setOccupancy(occ)
      setUnassigned(unasgn)
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const bid = activeBranchId ?? null
    setSelectedBranch(bid)
    load(bid)
  }, [activeBranchId])

  const grouped = STATUS_ORDER.reduce<Record<string, Kennel[]>>((acc, s) => {
    acc[s] = kennels.filter((k) => {
      if (s === 'Occupied') return occupancy[k.id] !== undefined
      if (s === 'Available') return k.status === 'Available' && !occupancy[k.id]
      return k.status === s
    })
    return acc
  }, {} as Record<string, Kennel[]>)

  const displayBranches = selectedBranch
    ? branches.filter((b) => b.id === selectedBranch)
    : branches

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading board…</div>

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Kennel Board</h1>
          <p className="text-sm text-gray-500 mt-0.5">Today — {new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => load(selectedBranch)} className="btn-secondary text-sm">↻ Refresh</button>
        </div>
      </div>

      {/* Summary strip */}
      <div className="flex flex-wrap gap-3 mb-5">
        {STATUS_ORDER.map((s) => {
          const st = STATUS_STYLES[s]
          return (
            <div key={s} className={`flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium ${st.section}`}>
              <span className={`w-2 h-2 rounded-full ${st.dot}`} />
              <span className={`${st.badge.split(' ').slice(1).join(' ')}`}>{s}</span>
              <span className="font-bold">{grouped[s].length}</span>
            </div>
          )
        })}
        {unassigned.length > 0 && (
          <div className="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 bg-gray-50 text-sm font-medium text-gray-600">
            <span className="w-2 h-2 rounded-full bg-gray-400" />
            Unassigned stays
            <span className="font-bold">{unassigned.length}</span>
          </div>
        )}
      </div>

      {kennels.length === 0 ? (
        <div className="empty-state">
          <span>🏠</span>
          <p>No kennels configured yet.</p>
          <p className="text-sm text-gray-400 mt-1">Go to Settings → Kennels to add kennel units.</p>
        </div>
      ) : (
        <div className="space-y-5">
          {STATUS_ORDER.map((status) => {
            const items = grouped[status]
            if (items.length === 0) return null
            const st = STATUS_STYLES[status]
            return (
              <div key={status}>
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-2">
                  <span className={`w-2 h-2 rounded-full ${st.dot}`} />
                  {status}
                  <span className="text-gray-400 font-normal normal-case">({items.length})</span>
                </h2>
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                  {items.map((k) => {
                    const occ = occupancy[k.id]
                    return (
                      <div
                        key={k.id}
                        className={`rounded-lg border-2 p-3 ${st.section} flex flex-col gap-1`}
                      >
                        <div className="flex items-center justify-between">
                          <span className="font-mono font-bold text-gray-900">{k.code}</span>
                          {occ && (
                            <span className={`text-xs px-1.5 py-0.5 rounded-full border font-medium ${
                              occ.status === 'Active' ? 'bg-green-100 text-green-800 border-green-200' : 'bg-blue-100 text-blue-800 border-blue-200'
                            }`}>
                              {occ.status}
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-600 truncate">{k.name}</div>
                        {occ ? (
                          <div className="mt-1 pt-1 border-t border-current border-opacity-20">
                            <Link
                              to={`/bookings/${occ.booking_id}`}
                              className="font-semibold text-sm text-blue-700 hover:underline block truncate"
                            >
                              {occ.pet_name}
                            </Link>
                            <div className="text-xs text-gray-500 truncate">{occ.client_name}</div>
                            <div className="text-xs text-gray-400 mt-0.5">
                              {occ.check_in_date} → {occ.check_out_date}
                            </div>
                          </div>
                        ) : (
                          status === 'Available' && (
                            <div className="mt-1 text-xs text-green-600 font-medium">Free</div>
                          )
                        )}
                        {k.notes && (
                          <div className="text-xs text-gray-400 italic truncate mt-0.5" title={k.notes}>{k.notes}</div>
                        )}
                      </div>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Unassigned stays */}
      {unassigned.length > 0 && (
        <div className="mt-6">
          <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-gray-400" />
            Unassigned Stays
            <span className="text-gray-400 font-normal normal-case">({unassigned.length})</span>
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            {unassigned.map((s) => (
              <div key={s.id} className="rounded-lg border border-gray-200 bg-white p-3">
                <div className="flex items-center justify-between mb-1">
                  <Link to={`/bookings/${s.booking_id}`} className="font-semibold text-blue-700 hover:underline">
                    {s.pet_name}
                  </Link>
                  <span className="text-xs text-gray-500">{s.status}</span>
                </div>
                <div className="text-xs text-gray-500">{s.client_name}</div>
                <div className="text-xs text-gray-400 mt-0.5">{s.check_in_date} → {s.check_out_date}</div>
                <div className="mt-2">
                  <Link to={`/bookings/${s.booking_id}/checkin`} className="text-xs text-blue-600 hover:underline">
                    Assign kennel →
                  </Link>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
