import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import type { Booking } from '../../api/bookings'
import { fmt } from '../../api/client'
import StatusBadge from '../../components/StatusBadge'
import WhatsAppButton from '../../components/WhatsAppButton'
import { useWhatsApp } from '../../hooks/useWhatsApp'

export default function BookingDetail() {
  const { id } = useParams<{ id: string }>()
  const [booking, setBooking] = useState<Booking | null>(null)
  const [loading, setLoading] = useState(true)
  const { invoiceMessage } = useWhatsApp()

  const load = () => {
    bookingsApi.get(Number(id))
      .then(setBooking)
      .catch(() => {})
      .finally(() => setLoading(false))
  }
  useEffect(load, [id])

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!booking) return <div className="alert-error">Booking not found</div>

  const inv = booking.invoice
  const pets = booking.stays?.map((s) => ({ name: s.pet_name ?? '', breed: s.breed })) ?? []
  const canCheckin = booking.stays?.some((s) => s.status === 'Upcoming')
  const canCheckout = booking.stays?.some((s) => s.status === 'Active')

  return (
    <div className="space-y-4">
      <div className="page-header">
        <div>
          <h1 className="page-title">Booking #{booking.id}</h1>
          <p className="text-sm text-gray-500">
            <Link to={`/clients/${booking.client_id}`} className="text-blue-600 hover:underline">{booking.client_name}</Link>
            {' · '}{booking.branch_name}{' · '}{fmt.date(booking.booking_date)}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canCheckin && <Link to={`/bookings/${id}/checkin`} className="btn-primary">Check In</Link>}
          {canCheckout && <Link to={`/bookings/${id}/checkout`} className="btn-primary">Check Out</Link>}
          {inv && booking.client_phone && (
            <WhatsAppButton
              phone={booking.client_phone}
              message={invoiceMessage(
                { name: booking.client_name!, phone: booking.client_phone },
                pets,
                { id: inv.id, revenue: inv.revenue, paid: inv.paid, due: inv.due, legacy_invoice_number: inv.legacy_invoice_number },
                booking.stays?.[0] ? { check_in_date: booking.stays[0].check_in_date, check_out_date: booking.stays[0].check_out_date } : undefined
              )}
              label="Send Invoice"
              size="sm"
            />
          )}
        </div>
      </div>

      {/* Stays */}
      <div className="card">
        <h2 className="font-semibold mb-3 border-b pb-2">Stays</h2>
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr><th>Pet</th><th>Type</th><th>Check-in</th><th>Check-out</th><th>Kennel</th><th>Status</th><th>Amount</th></tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {!booking.stays?.length ? (
                <tr><td colSpan={7} className="text-center py-4 text-gray-400">No stays</td></tr>
              ) : booking.stays.map((s) => (
                <tr key={s.id}>
                  <td>{s.pet_name} <span className="text-xs text-gray-400">({s.breed})</span></td>
                  <td><span className="badge-blue">{s.boarding_type}</span></td>
                  <td>{fmt.date(s.check_in_date)} {s.check_in_slot ? `(${s.check_in_slot})` : ''}</td>
                  <td>{fmt.date(s.check_out_date)} {s.check_out_slot ? `(${s.check_out_slot})` : ''}</td>
                  <td>{s.kennel ?? '—'}</td>
                  <td><StatusBadge value={s.status} type="stay" /></td>
                  <td>{s.final_amount != null ? fmt.inr(s.final_amount) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add-ons */}
      {booking.addons && booking.addons.length > 0 && (
        <div className="card">
          <h2 className="font-semibold mb-3 border-b pb-2">Add-on Services</h2>
          <div className="table-container">
            <table className="data-table">
              <thead><tr><th>Service</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
              <tbody className="bg-white">
                {booking.addons.map((a) => (
                  <tr key={a.id}>
                    <td>{a.name ?? `Addon #${a.addon_id}`}</td>
                    <td>{a.count}</td>
                    <td>{a.unit_price != null ? fmt.inr(a.unit_price) : '—'}</td>
                    <td>{a.final_amount != null ? fmt.inr(a.final_amount) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Invoice summary */}
      {inv && (
        <div className="card">
          <div className="flex items-center justify-between mb-3 border-b pb-2">
            <h2 className="font-semibold">Invoice #{inv.legacy_invoice_number ?? inv.id}</h2>
            <div className="flex items-center gap-2">
              <StatusBadge value={inv.payment_status} type="payment" />
              <Link to={`/invoices/${inv.id}`} className="text-sm text-blue-600 hover:underline">Full Invoice →</Link>
            </div>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
            {[
              ['Revenue', fmt.inr(inv.revenue)],
              ['Paid', fmt.inr(inv.paid)],
              ['Due', fmt.inr(inv.due)],
              ['Date', fmt.date(inv.invoice_date)],
            ].map(([k,v]) => (
              <div key={k}>
                <div className="text-xs text-gray-500">{k}</div>
                <div className="font-semibold text-gray-900">{v}</div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Notes */}
      {(booking.notes || booking.additional_instruction) && (
        <div className="card">
          <h2 className="font-semibold mb-2 border-b pb-2">Notes</h2>
          {booking.notes && <p className="text-sm text-gray-700 mb-1">{booking.notes}</p>}
          {booking.additional_instruction && <p className="text-sm text-gray-500 italic">{booking.additional_instruction}</p>}
        </div>
      )}
    </div>
  )
}
