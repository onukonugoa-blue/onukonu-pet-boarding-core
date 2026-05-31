import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import type { Booking, BookingStay } from '../../api/bookings'
import { fmt } from '../../api/client'

export default function CheckIn() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [booking, setBooking] = useState<Booking | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [stayData, setStayData] = useState<Record<number, { kennel: string; weight: string; companion_name: string; companion_phone: string; notes: string }>>({})

  useEffect(() => {
    bookingsApi.get(Number(id)).then((b) => {
      setBooking(b)
      const init: typeof stayData = {}
      b.stays?.filter((s) => s.status === 'Upcoming').forEach((s) => {
        init[s.id] = { kennel: s.kennel ?? '', weight: '', companion_name: '', companion_phone: '', notes: '' }
      })
      setStayData(init)
    }).catch(console.error).finally(() => setLoading(false))
  }, [id])

  const upcomingStays = booking?.stays?.filter((s) => s.status === 'Upcoming') ?? []

  const set = (stayId: number, k: string, v: string) =>
    setStayData((prev) => ({ ...prev, [stayId]: { ...prev[stayId], [k]: v } }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      for (const s of upcomingStays) {
        const d = stayData[s.id]
        await bookingsApi.checkin(Number(id), {
          stay_id:          s.id,
          kennel:           d?.kennel ?? '',
          weight_at_checkin: d?.weight ? Number(d.weight) : null,
          companion_name:   d?.companion_name ?? '',
          companion_phone:  d?.companion_phone ?? '',
          notes:            d?.notes ?? '',
        })
      }
      navigate(`/bookings/${id}`)
    } catch (e: any) {
      setError(e.message ?? 'Check-in failed')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!booking) return <div className="alert-error">Booking not found</div>
  if (upcomingStays.length === 0) return <div className="alert-info">No upcoming stays to check in.</div>

  return (
    <div>
      <h1 className="page-title mb-1">Check-In — Booking #{id}</h1>
      <p className="text-sm text-gray-500 mb-5">{booking.client_name} · {fmt.date(booking.booking_date)}</p>
      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="space-y-4 max-w-2xl">
        {upcomingStays.map((s) => (
          <div key={s.id} className="card">
            <h2 className="font-semibold border-b pb-2 mb-3">{s.pet_name} <span className="text-gray-500 text-sm">({s.breed})</span></h2>
            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label className="form-label">Kennel</label>
                <input className="form-input" value={stayData[s.id]?.kennel ?? ''} onChange={(e) => set(s.id,'kennel',e.target.value)} placeholder="e.g. K3" />
              </div>
              <div className="form-group">
                <label className="form-label">Weight at Check-in (kg)</label>
                <input className="form-input" type="number" step="0.1" value={stayData[s.id]?.weight ?? ''} onChange={(e) => set(s.id,'weight',e.target.value)} />
              </div>
              <div className="form-group">
                <label className="form-label">Companion Name</label>
                <input className="form-input" value={stayData[s.id]?.companion_name ?? ''} onChange={(e) => set(s.id,'companion_name',e.target.value)} />
              </div>
              <div className="form-group">
                <label className="form-label">Companion Phone</label>
                <input className="form-input" value={stayData[s.id]?.companion_phone ?? ''} onChange={(e) => set(s.id,'companion_phone',e.target.value)} />
              </div>
              <div className="form-group col-span-2">
                <label className="form-label">Notes</label>
                <textarea className="form-input" rows={2} value={stayData[s.id]?.notes ?? ''} onChange={(e) => set(s.id,'notes',e.target.value)} />
              </div>
            </div>
          </div>
        ))}

        <div className="flex gap-3">
          <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Checking in…' : 'Confirm Check-In'}</button>
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  )
}
