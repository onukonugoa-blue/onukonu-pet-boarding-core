import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import type { Booking } from '../../api/bookings'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import StatusBadge from '../../components/StatusBadge'
import Pagination from '../../components/Pagination'

export default function BookingList() {
  const [bookings, setBookings] = useState<Booking[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
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
    if (search) params.search = search
    if (status) params.status = status
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (activeBranchId) params.branch_id = activeBranchId
    bookingsApi.list(params)
      .then((r) => { setBookings(r.data); setTotal(r.total); setTotalPages(r.total_pages) })
      .catch(console.error)
      .finally(() => setLoading(false))
  }

  useEffect(() => { load(1); setPage(1) }, [activeBranchId])

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Bookings</h1>
        <Link to="/bookings/new" className="btn-primary">+ New Booking</Link>
      </div>

      <div className="card mb-4">
        <div className="flex flex-wrap gap-2">
          <input className="form-input w-44" placeholder="Search client/pet…" value={search} onChange={(e) => setSearch(e.target.value)} />
          <select className="form-select w-36" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All Statuses</option>
            {['Upcoming','Active','Completed','No show'].map((s) => <option key={s}>{s}</option>)}
          </select>
          <input className="form-input w-36" type="date" placeholder="From" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          <input className="form-input w-36" type="date" placeholder="To" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          <button onClick={() => { setPage(1); load(1) }} className="btn-primary">Filter</button>
          <button onClick={() => { setSearch(''); setStatus(''); setDateFrom(''); setDateTo(''); setPage(1); load(1) }} className="btn-secondary">Clear</button>
        </div>
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>ID</th><th>Client</th><th>Pets</th><th>Branch</th>
                <th>Check-in</th><th>Check-out</th><th>Status</th><th>Payment</th><th>Total</th><th></th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={10} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : bookings.length === 0 ? (
                <tr><td colSpan={10} className="text-center py-8 text-gray-400">No bookings found</td></tr>
              ) : bookings.map((b) => (
                <tr key={b.id} className="cursor-pointer" onClick={() => navigate(`/bookings/${b.id}`)}>
                  <td className="text-blue-600">#{b.id}</td>
                  <td className="font-medium">{b.client_name}</td>
                  <td>{b.pet_names ?? '—'}</td>
                  <td>{b.branch_code}</td>
                  <td>{fmt.date(b.check_in_date ?? null)}</td>
                  <td>{fmt.date(b.check_out_date ?? null)}</td>
                  <td>{b.stay_status ? <StatusBadge value={b.stay_status} type="stay" /> : '—'}</td>
                  <td><StatusBadge value={b.payment_status} type="payment" /></td>
                  <td>{fmt.inr(b.total_billing_amount)}</td>
                  <td><Link to={`/bookings/${b.id}`} className="text-blue-600 hover:underline text-xs">View</Link></td>
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
