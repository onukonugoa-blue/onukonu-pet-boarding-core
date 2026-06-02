import { useEffect, useRef, useState } from 'react'
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

const STATUS_STYLES: Record<string, { section: string; dot: string; header: string }> = {
  Available:   { section: 'border-green-200 bg-green-50',   dot: 'bg-green-500',  header: 'text-green-700' },
  Occupied:    { section: 'border-blue-200 bg-blue-50',     dot: 'bg-blue-500',   header: 'text-blue-700'  },
  Maintenance: { section: 'border-yellow-200 bg-yellow-50', dot: 'bg-yellow-500', header: 'text-yellow-700'},
  Blocked:     { section: 'border-red-200 bg-red-50',       dot: 'bg-red-500',    header: 'text-red-700'   },
}

// ── Quick-assign popover inside an available kennel card ──────────────────────
interface AssignPopoverProps {
  kennel: Kennel
  unassigned: BookingStay[]
  onAssign: (stayId: number, kennelId: number) => Promise<void>
  onClose: () => void
}

function AssignPopover({ kennel, unassigned, onAssign, onClose }: AssignPopoverProps) {
  const [selected, setSelected] = useState('')
  const [saving, setSaving] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  // Close on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) onClose()
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [onClose])

  const handleConfirm = async () => {
    if (!selected) return
    setSaving(true)
    try {
      await onAssign(Number(selected), kennel.id)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div
      ref={ref}
      className="absolute z-50 left-0 right-0 top-full mt-1 bg-white border border-green-300 rounded-lg shadow-lg p-3"
    >
      <p className="text-xs font-semibold text-gray-700 mb-2">Assign to {kennel.code}:</p>
      {unassigned.length === 0 ? (
        <p className="text-xs text-gray-400 italic">No unassigned stays today.</p>
      ) : (
        <>
          <select
            className="form-input text-xs mb-2 w-full"
            value={selected}
            onChange={(e) => setSelected(e.target.value)}
            autoFocus
          >
            <option value="">— Select a stay —</option>
            {unassigned.map((s) => (
              <option key={s.id} value={s.id}>
                {s.pet_name} · {s.client_name} ({s.status})
              </option>
            ))}
          </select>
          <div className="flex gap-2">
            <button
              onClick={handleConfirm}
              disabled={!selected || saving}
              className="btn-primary text-xs py-1 px-3 flex-1"
            >
              {saving ? 'Assigning…' : 'Confirm'}
            </button>
            <button onClick={onClose} className="btn-secondary text-xs py-1 px-2">✕</button>
          </div>
        </>
      )}
    </div>
  )
}

// ── Main board ────────────────────────────────────────────────────────────────
export default function OccupancyBoard() {
  const [kennels, setKennels]           = useState<Kennel[]>([])
  const [occupancy, setOccupancy]       = useState<Record<number, OccupiedInfo>>({})
  const [unassigned, setUnassigned]     = useState<BookingStay[]>([])
  const [branches, setBranches]         = useState<Branch[]>([])
  const [loading, setLoading]           = useState(true)
  const [assigningKennelId, setAssigningKennelId] = useState<number | null>(null)
  const [assigningStayId, setAssigningStayId]     = useState<number | null>(null)
  const [assigning, setAssigning]       = useState(false)
  const [flashId, setFlashId]           = useState<number | null>(null)
  const selectedBranch = useBranchStore((s) => s.activeBranchId) || null

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

      const occ: Record<number, OccupiedInfo> = {}
      const unasgn: BookingStay[] = []
      ;(board.stays ?? []).forEach((s: BookingStay) => {
        if (s.status === 'Completed' || s.status === 'No show') return
        if (s.kennel_id) {
          occ[s.kennel_id] = {
            stay_id: s.id, booking_id: s.booking_id,
            pet_name: s.pet_name ?? '', pet_type: s.pet_type ?? '',
            client_name: s.client_name ?? '',
            check_in_date: s.check_in_date, check_out_date: s.check_out_date,
            status: s.status,
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

  useEffect(() => { load(selectedBranch) }, [selectedBranch])

  // Quick-assign from kennel card
  const handleAssignFromKennel = async (stayId: number, kennelId: number) => {
    await bookingsApi.assignKennel(stayId, kennelId)
    setAssigningKennelId(null)
    setFlashId(kennelId)
    setTimeout(() => setFlashId(null), 1500)
    load(selectedBranch)
  }

  // Quick-assign from unassigned stay row
  const handleAssignFromStay = async (stayId: number, kennelId: number) => {
    if (!kennelId) return
    setAssigningStayId(stayId)
    setAssigning(true)
    try {
      await bookingsApi.assignKennel(stayId, kennelId)
      setFlashId(kennelId)
      setTimeout(() => setFlashId(null), 1500)
      load(selectedBranch)
    } finally {
      setAssigning(false)
      setAssigningStayId(null)
    }
  }

  const grouped = STATUS_ORDER.reduce<Record<string, Kennel[]>>((acc, s) => {
    acc[s] = kennels.filter((k) => {
      if (s === 'Occupied')  return occupancy[k.id] !== undefined
      if (s === 'Available') return k.status === 'Available' && !occupancy[k.id]
      return k.status === s
    })
    return acc
  }, {} as Record<string, Kennel[]>)

  const availableKennels = grouped['Available'] ?? []

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading board…</div>

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Kennel Board</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Today — {new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}
          </p>
        </div>
        <button onClick={() => load(selectedBranch)} className="btn-secondary text-sm">↻ Refresh</button>
      </div>

      {/* Summary strip */}
      <div className="flex flex-wrap gap-3 mb-5">
        {STATUS_ORDER.map((s) => {
          const st = STATUS_STYLES[s]
          const count = grouped[s].length
          if (count === 0) return null
          return (
            <div key={s} className={`flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium ${st.section}`}>
              <span className={`w-2 h-2 rounded-full ${st.dot}`} />
              <span className={st.header}>{s}</span>
              <span className="font-bold text-gray-800">{count}</span>
            </div>
          )
        })}
        {unassigned.length > 0 && (
          <div className="flex items-center gap-2 px-3 py-2 rounded-lg border border-orange-200 bg-orange-50 text-sm font-medium text-orange-700">
            <span className="w-2 h-2 rounded-full bg-orange-400" />
            Unassigned
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
        <div className="space-y-6">
          {STATUS_ORDER.map((status) => {
            const items = grouped[status]
            if (items.length === 0) return null
            const st = STATUS_STYLES[status]
            return (
              <div key={status}>
                <h2 className={`text-xs font-semibold uppercase tracking-widest mb-3 flex items-center gap-2 ${st.header}`}>
                  <span className={`w-2 h-2 rounded-full ${st.dot}`} />
                  {status}
                  <span className="text-gray-400 font-normal normal-case tracking-normal">({items.length})</span>
                </h2>
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                  {items.map((k) => {
                    const occ = occupancy[k.id]
                    const isFlashing = flashId === k.id
                    const isAssigning = assigningKennelId === k.id
                    return (
                      <div
                        key={k.id}
                        className={`relative rounded-lg border-2 p-3 flex flex-col gap-1 transition-all duration-300
                          ${isFlashing ? 'border-green-400 bg-green-100 scale-105 shadow-md' : st.section}
                        `}
                      >
                        {/* Header row */}
                        <div className="flex items-center justify-between">
                          <span className="font-mono font-bold text-gray-900 text-sm">{k.code}</span>
                          {occ && (
                            <span className={`text-xs px-1.5 py-0.5 rounded-full border font-medium ${
                              occ.status === 'Active'
                                ? 'bg-green-100 text-green-800 border-green-200'
                                : 'bg-blue-100 text-blue-800 border-blue-200'
                            }`}>
                              {occ.status}
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500 truncate">{k.name}</div>

                        {/* Occupied: show pet + client */}
                        {occ && (
                          <div className="mt-1 pt-1 border-t border-blue-200">
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
                        )}

                        {/* Available: show assign button if unassigned stays exist */}
                        {status === 'Available' && !occ && (
                          <div className="mt-1">
                            {unassigned.length > 0 ? (
                              <button
                                onClick={() => setAssigningKennelId(isAssigning ? null : k.id)}
                                className="w-full text-xs font-medium text-green-700 hover:text-green-900 hover:bg-green-100 rounded px-2 py-1 transition-colors border border-green-200 bg-white"
                              >
                                {isAssigning ? 'Cancel' : '＋ Assign stay'}
                              </button>
                            ) : (
                              <span className="text-xs text-green-600 font-medium">Free</span>
                            )}
                          </div>
                        )}

                        {k.notes && (
                          <div className="text-xs text-gray-400 italic truncate" title={k.notes}>{k.notes}</div>
                        )}

                        {/* Assign popover */}
                        {isAssigning && (
                          <AssignPopover
                            kennel={k}
                            unassigned={unassigned}
                            onAssign={handleAssignFromKennel}
                            onClose={() => setAssigningKennelId(null)}
                          />
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
          <h2 className="text-xs font-semibold text-orange-600 uppercase tracking-widest mb-3 flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-orange-400" />
            Unassigned Stays
            <span className="text-gray-400 font-normal normal-case tracking-normal">({unassigned.length})</span>
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            {unassigned.map((s) => (
              <div key={s.id} className="rounded-lg border border-orange-200 bg-orange-50 p-3">
                <div className="flex items-center justify-between mb-1">
                  <Link to={`/bookings/${s.booking_id}`} className="font-semibold text-blue-700 hover:underline">
                    {s.pet_name}
                  </Link>
                  <span className={`text-xs px-1.5 py-0.5 rounded-full border font-medium ${
                    s.status === 'Active'
                      ? 'bg-green-100 text-green-800 border-green-200'
                      : 'bg-blue-100 text-blue-800 border-blue-200'
                  }`}>{s.status}</span>
                </div>
                <div className="text-xs text-gray-600">{s.client_name}</div>
                <div className="text-xs text-gray-400 mt-0.5 mb-2">{s.check_in_date} → {s.check_out_date}</div>

                {/* Inline kennel picker */}
                {availableKennels.length > 0 ? (
                  <div className="flex gap-2 items-center">
                    <select
                      className="form-input text-xs flex-1 py-1"
                      defaultValue=""
                      disabled={assigning && assigningStayId === s.id}
                      onChange={(e) => {
                        if (e.target.value) handleAssignFromStay(s.id, Number(e.target.value))
                      }}
                    >
                      <option value="">Assign to kennel…</option>
                      {availableKennels.map((k) => (
                        <option key={k.id} value={k.id}>{k.code} — {k.name}</option>
                      ))}
                    </select>
                    {assigning && assigningStayId === s.id && (
                      <span className="text-xs text-gray-400">Saving…</span>
                    )}
                  </div>
                ) : (
                  <Link to={`/bookings/${s.booking_id}/checkin`} className="text-xs text-blue-600 hover:underline">
                    Go to check-in →
                  </Link>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
