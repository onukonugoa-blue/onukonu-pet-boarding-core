import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import type { Booking } from '../../api/bookings'
import { fmt, ApiError } from '../../api/client'

export default function BookingEdit() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [booking, setBooking]   = useState<Booking | null>(null)
  const [loading, setLoading]   = useState(true)
  const [saving, setSaving]     = useState(false)
  const [error, setError]       = useState('')

  const [notes, setNotes]                           = useState('')
  const [additionalInstruction, setAdditionalInstruction] = useState('')
  const [stayDates, setStayDates] = useState<Record<number, {
    check_in_date: string
    check_out_date: string
  }>>({})

  useEffect(() => {
    bookingsApi.get(Number(id))
      .then((b) => {
        setBooking(b)
        setNotes(b.notes ?? '')
        setAdditionalInstruction(b.additional_instruction ?? '')
        const init: typeof stayDates = {}
        b.stays?.filter((s) => s.status === 'Upcoming').forEach((s) => {
          init[s.id] = { check_in_date: s.check_in_date, check_out_date: s.check_out_date }
        })
        setStayDates(init)
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [id])

  const upcomingStays = booking?.stays?.filter((s) => s.status === 'Upcoming') ?? []

  const setDate = (stayId: number, field: 'check_in_date' | 'check_out_date', value: string) =>
    setStayDates((prev) => ({ ...prev, [stayId]: { ...prev[stayId], [field]: value } }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')

    // Client-side date validation
    for (const s of upcomingStays) {
      const d = stayDates[s.id]
      if (d && d.check_out_date <= d.check_in_date) {
        setError(`Check-out date must be after check-in date for ${s.pet_name}.`)
        return
      }
    }

    setSaving(true)
    try {
      await bookingsApi.update(Number(id), {
        notes,
        additional_instruction: additionalInstruction,
        stays: upcomingStays.map((s) => ({
          id:             s.id,
          check_in_date:  stayDates[s.id]?.check_in_date  ?? s.check_in_date,
          check_out_date: stayDates[s.id]?.check_out_date ?? s.check_out_date,
        })),
      })
      navigate(`/bookings/${id}`)
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Save failed. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!booking) return <div className="alert-error">Booking not found</div>

  if (booking.status === 'Cancelled') return (
    <div className="alert-error">
      This booking is cancelled and cannot be edited.{' '}
      <Link to={`/bookings/${id}`} className="underline">Back to booking</Link>
    </div>
  )

  if (upcomingStays.length === 0) return (
    <div className="alert-info">
      No upcoming stays to edit — bookings can only be edited before check-in.{' '}
      <Link to={`/bookings/${id}`} className="underline">Back to booking</Link>
    </div>
  )

  return (
    <div className="space-y-4 max-w-2xl">
      <div className="page-header">
        <div>
          <h1 className="page-title">Edit Booking #{booking.id}</h1>
          <p className="text-sm text-gray-500">
            <Link to={`/clients/${booking.client_id}`} className="text-blue-600 hover:underline">{booking.client_name}</Link>
            {' · '}{booking.branch_name}{' · '}{fmt.date(booking.booking_date)}
          </p>
        </div>
      </div>

      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="space-y-4">

        {/* Per-stay date editing */}
        <div className="card">
          <h2 className="font-semibold mb-3 border-b pb-2">Stay Dates</h2>
          <div className="space-y-5">
            {upcomingStays.map((s) => (
              <div key={s.id}>
                <p className="text-sm font-medium text-gray-700 mb-2">
                  {s.pet_name}
                  {s.breed ? <span className="text-gray-400 font-normal"> ({s.breed})</span> : null}
                  {' '}
                  <span className="badge-blue">{s.boarding_type}</span>
                </p>
                <div className="grid grid-cols-2 gap-3">
                  <div className="form-group">
                    <label className="form-label">Check-in Date</label>
                    <input
                      type="date"
                      className="form-input"
                      value={stayDates[s.id]?.check_in_date ?? ''}
                      onChange={(e) => setDate(s.id, 'check_in_date', e.target.value)}
                      required
                    />
                  </div>
                  <div className="form-group">
                    <label className="form-label">Check-out Date</label>
                    <input
                      type="date"
                      className="form-input"
                      value={stayDates[s.id]?.check_out_date ?? ''}
                      onChange={(e) => setDate(s.id, 'check_out_date', e.target.value)}
                      required
                    />
                  </div>
                </div>
              </div>
            ))}
          </div>
          <p className="text-xs text-gray-400 mt-3">
            Changing dates will automatically recalculate the projected invoice.
          </p>
        </div>

        {/* Booking notes */}
        <div className="card">
          <h2 className="font-semibold mb-3 border-b pb-2">Notes</h2>
          <div className="space-y-3">
            <div className="form-group">
              <label className="form-label">Booking Notes</label>
              <textarea
                className="form-input"
                rows={2}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>
            <div className="form-group">
              <label className="form-label">Additional Instructions</label>
              <textarea
                className="form-input"
                rows={2}
                value={additionalInstruction}
                onChange={(e) => setAdditionalInstruction(e.target.value)}
              />
            </div>
          </div>
        </div>

        <div className="flex gap-3">
          <button type="submit" disabled={saving} className="btn-primary">
            {saving ? 'Saving…' : 'Save Changes'}
          </button>
          <Link to={`/bookings/${id}`} className="btn-secondary">Cancel</Link>
        </div>
      </form>
    </div>
  )
}
