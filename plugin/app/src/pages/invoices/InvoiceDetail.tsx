import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { invoicesApi } from '../../api/invoices'
import type { InvoiceDetail as InvoiceDetailType } from '../../api/invoices'
import { invoiceDeliveryApi } from '../../api/invoice-delivery'
import type { InvoiceDocument } from '../../api/invoice-delivery'
import { fmt } from '../../api/client'
import StatusBadge from '../../components/StatusBadge'
import Modal from '../../components/Modal'
import WhatsAppButton from '../../components/WhatsAppButton'
import { useWhatsApp } from '../../hooks/useWhatsApp'

const MODES = ['Cash','UPI','Card','Bank Transfer','Other']

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>()

  // Invoice
  const [invoice, setInvoice] = useState<InvoiceDetailType | null>(null)
  const [loading, setLoading]   = useState(true)

  // Modals
  const [payModal, setPayModal] = useState(false)
  const [adjModal, setAdjModal] = useState(false)
  const [payForm,  setPayForm]  = useState({ amount: '', mode: 'Cash', notes: '', transaction_id: '' })
  const [adjForm,  setAdjForm]  = useState({ amount: '', description: '', is_discount: false })
  const [saving,   setSaving]   = useState(false)

  // Invoice document
  const [docInfo,       setDocInfo]       = useState<InvoiceDocument | null>(null)
  const [docLoading,    setDocLoading]    = useState(false)
  const [docGenerating, setDocGenerating] = useState(false)
  const [docError,      setDocError]      = useState<string | null>(null)
  const [urlCopied,     setUrlCopied]     = useState(false)

  // Email delivery
  const [emailTo,      setEmailTo]      = useState('')
  const [emailSending, setEmailSending] = useState(false)
  const [emailResult,  setEmailResult]  = useState<{ success: boolean; message: string } | null>(null)

  // WhatsApp (link)
  const [waLoading, setWaLoading] = useState(false)

  const { invoiceMessage } = useWhatsApp()

  const loadInvoice = () => {
    setLoading(true)
    invoicesApi.get(Number(id))
      .then((inv) => {
        setInvoice(inv)
        const email = (inv as any).client_email ?? ''
        if (email) setEmailTo(email)
      })
      .catch(console.error)
      .finally(() => setLoading(false))
  }

  const loadDoc = () => {
    setDocLoading(true)
    invoiceDeliveryApi.getDocument(Number(id))
      .then((info) => setDocInfo(info))
      .catch(() => setDocInfo(null))
      .finally(() => setDocLoading(false))
  }

  useEffect(() => {
    loadInvoice()
    loadDoc()
  }, [id])

  // ── Invoice actions ────────────────────────────────────────────────────────

  const handlePayment = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await invoicesApi.recordPayment(Number(id), {
        amount: Number(payForm.amount),
        mode: payForm.mode,
        notes: payForm.notes,
        transaction_id: payForm.transaction_id || undefined,
      })
      setPayModal(false)
      loadInvoice()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleAdjust = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await invoicesApi.adjust(Number(id), {
        amount: Number(adjForm.amount),
        description: adjForm.description,
        is_discount: adjForm.is_discount,
      })
      setAdjModal(false)
      loadInvoice()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  // ── Document actions ───────────────────────────────────────────────────────

  const handleGenerate = async () => {
    setDocGenerating(true)
    setDocError(null)
    try {
      const info = await invoiceDeliveryApi.generateDocument(Number(id))
      setDocInfo(info)
    } catch (e: any) {
      setDocError(e.message ?? 'Failed to generate document.')
    } finally {
      setDocGenerating(false)
    }
  }

  const handleSendEmail = async () => {
    setEmailSending(true)
    setEmailResult(null)
    try {
      const res = await invoiceDeliveryApi.sendEmail(Number(id), emailTo)
      setEmailResult({
        success: res.sent,
        message: res.sent ? `Email sent to ${res.to}` : 'Delivery failed — check your mail server.',
      })
    } catch (e: any) {
      setEmailResult({ success: false, message: e.message ?? 'Failed to send email.' })
    } finally {
      setEmailSending(false)
    }
  }

  const handleWhatsApp = async () => {
    setWaLoading(true)
    setDocError(null)
    try {
      const link = await invoiceDeliveryApi.getWhatsAppLink(Number(id))
      window.open(link.url, '_blank', 'noopener')
    } catch (e: any) {
      setDocError(e.message ?? 'Failed to build WhatsApp link.')
    } finally {
      setWaLoading(false)
    }
  }

  const copyUrl = async (url: string) => {
    try {
      await navigator.clipboard.writeText(url)
      setUrlCopied(true)
      setTimeout(() => setUrlCopied(false), 1500)
    } catch {}
  }

  // ── Render ─────────────────────────────────────────────────────────────────

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!invoice) return <div className="alert-error">Invoice not found</div>

  const pets = (invoice.stays as any[] ?? []).map((s: any) => ({ name: s.pet_name ?? '', breed: s.breed }))

  return (
    <div className="space-y-4 max-w-3xl">
      <div className="page-header">
        <div>
          <h1 className="page-title">Invoice #{invoice.legacy_invoice_number ?? invoice.id}</h1>
          <p className="text-sm text-gray-500">
            <Link to={`/clients/${invoice.booking_id}`} className="hover:underline">{invoice.client_name}</Link>
            {' · '}{invoice.branch_name}{' · '}{fmt.date(invoice.invoice_date)}
          </p>
        </div>
        <div className="flex gap-2">
          {invoice.client_phone && (
            <WhatsAppButton
              phone={invoice.client_phone}
              message={invoiceMessage(
                { name: invoice.client_name!, phone: invoice.client_phone },
                pets.length ? pets : [{ name: 'Pet' }],
                { id: invoice.id, revenue: invoice.revenue, paid: invoice.paid, due: invoice.due, legacy_invoice_number: invoice.legacy_invoice_number }
              )}
              label="Quick Send"
              size="sm"
            />
          )}
          {invoice.due > 0 && <button onClick={() => setPayModal(true)} className="btn-primary btn-sm">+ Payment</button>}
          <button onClick={() => setAdjModal(true)} className="btn-secondary btn-sm">Adjust</button>
          <Link to={`/bookings/${invoice.booking_id}`} className="btn-secondary btn-sm">Booking →</Link>
        </div>
      </div>

      {/* Summary */}
      <div className="card">
        <div className="flex items-center gap-3 mb-4">
          <StatusBadge value={invoice.payment_status} type="payment" />
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {[
            ['Base Amount',    fmt.inr(invoice.base_amount)],
            ['Add-on Amount',  fmt.inr(invoice.addon_amount)],
            ['Discount',       fmt.inr(invoice.discount_amount)],
            ['Additional',     fmt.inr(invoice.additional_amount)],
            ['Revenue',        fmt.inr(invoice.revenue)],
            ['Paid',           fmt.inr(invoice.paid)],
            ['Due',            fmt.inr(invoice.due)],
          ].map(([k, v]) => (
            <div key={k}>
              <div className="text-xs text-gray-500">{k}</div>
              <div className={`font-bold text-sm ${k === 'Due' && invoice.due > 0 ? 'text-red-600' : k === 'Paid' ? 'text-green-600' : ''}`}>{v}</div>
            </div>
          ))}
        </div>
      </div>

      {/* ── Invoice Document card ─────────────────────────────────────────── */}
      <div className="card">
        <div className="flex items-center justify-between mb-3 border-b pb-2">
          <h2 className="font-semibold text-sm flex items-center gap-1.5">
            <span>🧾</span> Invoice Document
          </h2>
          {docInfo && !docLoading && (
            <span className="text-xs text-gray-400">Generated {fmt.datetime(docInfo.generated_at)}</span>
          )}
        </div>

        {docLoading ? (
          <p className="text-sm text-gray-400 py-2">Checking…</p>
        ) : docInfo ? (
          <div className="space-y-3">
            {/* Public URL row */}
            <div className="flex items-center gap-2">
              <input
                type="text"
                readOnly
                value={docInfo.url}
                className="form-input flex-1 text-xs font-mono bg-gray-50"
                onFocus={(e) => e.target.select()}
              />
              <a
                href={docInfo.url}
                target="_blank"
                rel="noopener noreferrer"
                className="btn-secondary btn-sm whitespace-nowrap"
              >
                Open
              </a>
              <a
                href={docInfo.url + '?print=1'}
                target="_blank"
                rel="noopener noreferrer"
                className="btn-secondary btn-sm whitespace-nowrap"
                title="Opens invoice with auto-print dialog for PDF export"
              >
                🖨 Print PDF
              </a>
              <button
                onClick={() => copyUrl(docInfo.url)}
                className="btn-secondary btn-sm whitespace-nowrap"
              >
                {urlCopied ? '✓ Copied' : 'Copy'}
              </button>
            </div>

            {/* Email row */}
            <div className="border-t pt-3">
              <div className="flex gap-2">
                <input
                  type="email"
                  className="form-input flex-1 text-sm"
                  placeholder="Email address"
                  value={emailTo}
                  onChange={(e) => { setEmailTo(e.target.value); setEmailResult(null) }}
                />
                <button
                  onClick={handleSendEmail}
                  disabled={emailSending || !emailTo}
                  className="btn-secondary btn-sm whitespace-nowrap disabled:opacity-50"
                >
                  {emailSending ? 'Sending…' : '📧 Send Email'}
                </button>
              </div>
              {emailResult && (
                <p className={`text-xs mt-1.5 ${emailResult.success ? 'text-green-600' : 'text-red-600'}`}>
                  {emailResult.success ? '✓ ' : '⚠ '}{emailResult.message}
                </p>
              )}
            </div>

            {/* WhatsApp row */}
            {invoice.client_phone && (
              <div className="flex gap-2 border-t pt-3">
                <button
                  onClick={handleWhatsApp}
                  disabled={waLoading}
                  className="btn-secondary btn-sm disabled:opacity-50"
                >
                  {waLoading ? '…' : '💬 Share Invoice on WhatsApp'}
                </button>
                <span className="text-xs text-gray-400 self-center">Sends invoice link via WhatsApp</span>
              </div>
            )}

            {/* Regenerate */}
            <div className="pt-1">
              <button
                onClick={handleGenerate}
                disabled={docGenerating}
                className="text-xs text-gray-400 hover:text-gray-600 transition-colors disabled:opacity-50"
              >
                {docGenerating ? '↻ Regenerating…' : '↻ Regenerate document'}
              </button>
            </div>
          </div>
        ) : (
          <div className="text-center py-5">
            <p className="text-sm text-gray-500 mb-3">
              No invoice document generated yet. Generate one to share a link, send email, or export as PDF.
            </p>
            <button
              onClick={handleGenerate}
              disabled={docGenerating}
              className="btn-primary btn-sm disabled:opacity-50"
            >
              {docGenerating ? 'Generating…' : '⚡ Generate Invoice Document'}
            </button>
          </div>
        )}

        {docError && (
          <p className="text-xs text-red-600 mt-2 border-t pt-2">⚠ {docError}</p>
        )}
      </div>

      {/* Line items */}
      <div className="card">
        <h2 className="font-semibold mb-3 border-b pb-2">Line Items</h2>
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr><th>Section</th><th>Description</th><th>Qty</th><th>Unit</th><th>Total</th></tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {invoice.line_items?.map((li) => (
                <tr key={li.id} className={li.is_return ? 'text-red-600' : ''}>
                  <td><span className="badge-gray">{li.bill_section}</span></td>
                  <td>{li.bill_item_name}</td>
                  <td>{li.quantity}</td>
                  <td>{fmt.inr(li.amount)}</td>
                  <td className="font-medium">{fmt.inr(li.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Payments */}
      <div className="card">
        <h2 className="font-semibold mb-3 border-b pb-2">Payments</h2>
        {!invoice.payments?.length ? (
          <p className="text-sm text-gray-400">No payments recorded</p>
        ) : (
          <div className="table-container">
            <table className="data-table">
              <thead><tr><th>Date</th><th>Mode</th><th>Amount</th><th>Transaction ID</th><th>Notes</th></tr></thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {invoice.payments.map((p) => (
                  <tr key={p.id}>
                    <td>{fmt.datetime(p.paid_at)}</td>
                    <td>{p.mode}</td>
                    <td className="font-medium text-green-700">{fmt.inr(p.amount)}</td>
                    <td>{p.transaction_id ?? '—'}</td>
                    <td>{p.notes ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Payment modal */}
      <Modal open={payModal} onClose={() => setPayModal(false)} title="Record Payment"
        footer={<><button onClick={() => setPayModal(false)} className="btn-secondary">Cancel</button><button form="pay-form" type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : 'Save'}</button></>}>
        <form id="pay-form" onSubmit={handlePayment} className="space-y-3">
          <div className="form-group"><label className="form-label">Amount *</label><input className="form-input" type="number" step="0.01" required value={payForm.amount} onChange={(e) => setPayForm((p) => ({...p, amount: e.target.value}))} placeholder={fmt.inr(invoice.due)} /></div>
          <div className="form-group"><label className="form-label">Mode</label><select className="form-select" value={payForm.mode} onChange={(e) => setPayForm((p) => ({...p, mode: e.target.value}))}>{MODES.map((m) => <option key={m}>{m}</option>)}</select></div>
          <div className="form-group"><label className="form-label">Transaction ID</label><input className="form-input" value={payForm.transaction_id} onChange={(e) => setPayForm((p) => ({...p, transaction_id: e.target.value}))} /></div>
          <div className="form-group"><label className="form-label">Notes</label><input className="form-input" value={payForm.notes} onChange={(e) => setPayForm((p) => ({...p, notes: e.target.value}))} /></div>
        </form>
      </Modal>

      {/* Adjustment modal */}
      <Modal open={adjModal} onClose={() => setAdjModal(false)} title="Adjust Invoice"
        footer={<><button onClick={() => setAdjModal(false)} className="btn-secondary">Cancel</button><button form="adj-form" type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : 'Apply'}</button></>}>
        <form id="adj-form" onSubmit={handleAdjust} className="space-y-3">
          <div className="form-group"><label className="form-label">Amount *</label><input className="form-input" type="number" step="0.01" required value={adjForm.amount} onChange={(e) => setAdjForm((p) => ({...p, amount: e.target.value}))} /></div>
          <div className="form-group"><label className="form-label">Description *</label><input className="form-input" required value={adjForm.description} onChange={(e) => setAdjForm((p) => ({...p, description: e.target.value}))} /></div>
          <div className="flex items-center gap-2"><input id="is-disc" type="checkbox" checked={adjForm.is_discount} onChange={(e) => setAdjForm((p) => ({...p, is_discount: e.target.checked}))} /><label htmlFor="is-disc" className="text-sm">Apply as Discount</label></div>
        </form>
      </Modal>
    </div>
  )
}
