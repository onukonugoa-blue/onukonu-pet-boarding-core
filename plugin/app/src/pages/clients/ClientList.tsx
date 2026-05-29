import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { clientsApi } from '../../api/clients'
import type { Client } from '../../api/clients'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import Pagination from '../../components/Pagination'

export default function ClientList() {
  const [clients, setClients] = useState<Client[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const navigate = useNavigate()
  const perPage = 25

  const load = (p = 1, q = search) => {
    setLoading(true)
    const params: Record<string, unknown> = { page: p, per_page: perPage }
    if (q) params.search = q
    if (activeBranchId) params.branch_id = activeBranchId
    clientsApi.list(params)
      .then((r) => { setClients(r.data); setTotal(r.total); setTotalPages(r.total_pages) })
      .catch(console.error)
      .finally(() => setLoading(false))
  }

  useEffect(() => { load(1); setPage(1) }, [activeBranchId])

  const handleSearch = (e: React.FormEvent) => { e.preventDefault(); setPage(1); load(1) }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Clients</h1>
        <Link to="/clients/new" className="btn-primary">+ New Client</Link>
      </div>

      <div className="card mb-4">
        <form onSubmit={handleSearch} className="flex gap-2">
          <input
            className="form-input flex-1"
            placeholder="Search by name, phone, email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <button type="submit" className="btn-primary">Search</button>
          {search && (
            <button type="button" onClick={() => { setSearch(''); setPage(1); load(1,'') }} className="btn-secondary">Clear</button>
          )}
        </form>
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Branch</th>
                <th>Pets</th>
                <th>Wallet</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={8} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : clients.length === 0 ? (
                <tr><td colSpan={8} className="text-center py-8 text-gray-400">No clients found</td></tr>
              ) : clients.map((c) => (
                <tr key={c.id} className="cursor-pointer" onClick={() => navigate(`/clients/${c.id}`)}>
                  <td className="font-medium text-blue-600 hover:underline">{c.name}</td>
                  <td>{c.phone}</td>
                  <td>{c.email ?? '—'}</td>
                  <td>{c.branch_code ?? '—'}</td>
                  <td>{c.pet_count ?? 0}</td>
                  <td>{c.wallet_balance !== 0 ? fmt.inr(c.wallet_balance) : '—'}</td>
                  <td><span className={c.status === 'active' ? 'badge-green' : 'badge-gray'}>{c.status}</span></td>
                  <td><Link to={`/clients/${c.id}`} className="text-blue-600 hover:underline text-xs">View</Link></td>
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
