import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import { invoicesApi } from '../../api/invoices'
import type { Booking } from '../../api/bookings'
import type { InvoiceDetail } from '../../api/invoices'
import { fmt } from '../../api/client'
import WhatsAppButton from '../../components/WhatsAppButton'
import { useWhatsApp } from '../../hooks/useWhatsApp'

const MODES = ['Cash', 'UPI', 'Other']

export default function CheckOut() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [booking, setBooking] = useState<Booking | null>(null)
  const [invoice, setInvoice] = useState<InvoiceDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [weights, setWeights] = useState<Record<number, string>>({})
  const [payment, setPayment] = useState({ amount: '', mode: 'Cash', notes: '' })
  const { invoiceMessage } = useWhatsApp()

  useEffect(() => {
    bookingsApi.get(Number(id)).then(async (b) => {
      setBooking(b)
      const wi: Record<number,string> = {}
      b.stays?.filter((s) => s.status === 'Active').forEach((s) => { wi[s.id] = '' })
      setWeights(wi)
      if (b.invoice) {
        const inv = await invoicesApi.get(b.invoice.id)
        setInvoice(inv)
        setPayment((p) => ({ ...p, amount: String(inv.due > 0 ? inv.due : '') }))
      }
    }).catch(() => {}).finally(() => setLoading(false))
  }, [id])

  const activeStays = booking?.stays?.filter((s) => s.status === 'Active') ?? []

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    setSaving(true)
    try {
      for (const s of activeStays) {
        await bookingsApi.checkout(Number(id), {
          stay_id:           s.id,
          weight_at_checkout: weights[s.id] ? Number(weights[s.id]) : null,
        })
      }
      if (payment.amount && Number(payment.amount) > 0 && invoice) {
        await invoicesApi.recordPayment(invoice.id, {
          amount: Number(payment.amount),
          mode:   payment.mode,
          notes:  payment.notes || undefined,
        })
      }
      navigate(`/bookings/${id}`)
    } catch (e: any) {
      setError(e.message ?? 'Check-out failed')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!booking) return <div className="alert-error">Booking not found</div>
  if (activeStays.length === 0) return <div className="alert-info">No active stays to check out.</div>

  const pets = activeStays.map((s) => ({ name: s.pet_name ?? '', breed: s.breed }))

  return (
    <div>
      <h1 className="page-title mb-1">Check-Out — Booking #{id}</h1>
      <p className="text-sm text-gray-500 mb-5">{booking.client_name} · {booking.client_phone}</p>
      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="space-y-5 max-w-2xl">
        {/* Weights */}
        <div className="card">
          <h2 className="font-semibold border-b pb-2 mb-3">Check-out Weights</h2>
          {activeStays.map((s) => (
            <div key={s.id} className="flex items-center gap-4 mb-2">
              <span className="font-medium text-sm w-24">{s.pet_name}</span>
              <input
                className="form-input w-32"
                type="number"
                step="0.1"
                placeholder="kg"
                value={weights[s.id] ?? ''}
                onChange={(e) => setWeights((w) => ({ ...w, [s.id]: e.target.value }))}
              />
            </div>
          ))}
        </div>

        {/* Invoice summary */}
        {invoice && (
          <div className="card">
            <div className="flex justify-between items-center border-b pb-2 mb-3">
              <h2 className="font-semibold">Invoice #{invoice.legacy_invoice_number ?? invoice.id}</h2>
              {booking.client_phone && (
                <WhatsAppButton
                  phone={booking.client_phone}
                  message={invoiceMessage(
                    { name: booking.client_name!, phone: booking.client_phone },
                    pets,
                    { id: invoice.id, revenue: invoice.revenue, paid: invoice.paid, due: invoice.due, legacy_invoice_number: invoice.legacy_invoice_number }
                  )}
                  label="Send Invoice"
                  size="sm"
                />
              )}
            </div>
            <div className="grid grid-cols-3 gap-4 mb-4">
              {[['Revenue', fmt.inr(invoice.revenue)], ['Paid', fmt.inr(invoice.paid)], ['Due', fmt.inr(invoice.due)]].map(([k,v]) => (
                <div key={k}><div className="text-xs text-gray-500">{k}</div><div className="font-bold text-lg">{v}</div></div>
              ))}
            </div>
          </div>
        )}

        {/* Payment */}
        {invoice && invoice.due > 0 && (
          <div className="card">
            <h2 className="font-semibold border-b pb-2 mb-3">Record Payment</h2>
            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label className="form-label">Amount</label>
                <input className="form-input" type="number" step="0.01" value={payment.amount} onChange={(e) => setPayment((p) => ({ ...p, amount: e.target.value }))} />
              </div>
              <div className="form-group">
                <label className="form-label">Mode</label>
                <select className="form-select" value={payment.mode} onChange={(e) => setPayment((p) => ({ ...p, mode: e.target.value }))}>
                  {MODES.map((m) => <option key={m}>{m}</option>)}
                </select>
              </div>
              <div className="form-group col-span-2">
                <label className="form-label">Notes</label>
                <input className="form-input" value={payment.notes} onChange={(e) => setPayment((p) => ({ ...p, notes: e.target.value }))} />
              </div>
            </div>
          </div>
        )}

        <div className="flex gap-3">
          <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Checking out…' : 'Confirm Check-Out'}</button>
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  )
}
