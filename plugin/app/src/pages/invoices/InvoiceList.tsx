import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { invoicesApi } from '../../api/invoices'
import type { Invoice } from '../../api/bookings'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import StatusBadge from '../../components/StatusBadge'
import Pagination from '../../components/Pagination'

export default function InvoiceList() {
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [loading, setLoading] = useState(true)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const navigate = useNavigate()
  const perPage = 25

  const load = (p = 1) => {
    setLoading(true)
    const params: Record<string, unknown> = { page: p, per_page: perPage }
    if (status) params.payment_status = status
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (activeBranchId) params.branch_id = activeBranchId
    invoicesApi.list(params)
      .then((r) => { setInvoices(r.data); setTotal(r.total); setTotalPages(r.total_pages) })
      .catch(console.error)
      .finally(() => setLoading(false))
  }

  useEffect(() => { load(1); setPage(1) }, [activeBranchId])

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Invoices</h1>
      </div>

      <div className="card mb-4">
        <div className="flex flex-wrap gap-2">
          <select className="form-select w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All Statuses</option>
            {['Unpaid','Partially paid','Paid','Overpaid'].map((s) => <option key={s}>{s}</option>)}
          </select>
          <input className="form-input w-36" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          <input className="form-input w-36" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          <button onClick={() => { setPage(1); load(1) }} className="btn-primary">Filter</button>
          <button onClick={() => { setStatus(''); setDateFrom(''); setDateTo(''); setPage(1); load(1) }} className="btn-secondary">Clear</button>
        </div>
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Invoice #</th><th>Date</th><th>Client</th><th>Branch</th>
                <th>Revenue</th><th>Paid</th><th>Due</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={9} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : invoices.length === 0 ? (
                <tr><td colSpan={9} className="text-center py-8 text-gray-400">No invoices found</td></tr>
              ) : invoices.map((inv) => (
                <tr key={inv.id} className="cursor-pointer" onClick={() => navigate(`/invoices/${inv.id}`)}>
                  <td className="font-medium text-blue-600">{inv.legacy_invoice_number ?? inv.id}</td>
                  <td>{fmt.date(inv.invoice_date)}</td>
                  <td>{inv.client_name}</td>
                  <td>{inv.branch_name}</td>
                  <td>{fmt.inr(inv.revenue)}</td>
                  <td className="text-green-700">{fmt.inr(inv.paid)}</td>
                  <td className={Number(inv.due) > 0 ? 'text-red-600 font-medium' : ''}>{fmt.inr(inv.due)}</td>
                  <td><StatusBadge value={inv.payment_status} type="payment" /></td>
                  <td><Link to={`/invoices/${inv.id}`} className="text-blue-600 hover:underline text-xs">View</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} total={total} perPage={perPage} onPage={(p) => { setPage(p); load(p) }} />
      </div>
    </div>
  )
}
