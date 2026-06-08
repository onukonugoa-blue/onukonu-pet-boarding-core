import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { clientsApi } from '../../api/clients'
import type { Client } from '../../api/clients'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import Pagination from '../../components/Pagination'
import { useWhatsApp } from '../../hooks/useWhatsApp'

export default function ClientList() {
  const [clients, setClients] = useState<Client[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const navigate = useNavigate()
  const { clientPortalMessage, open } = useWhatsApp()
  const perPage = 25

  const load = (p = 1, q = search) => {
    setLoading(true)
    const params: Record<string, unknown> = { page: p, per_page: perPage }
    if (q) params.search = q
    if (activeBranchId) params.branch_id = activeBranchId
    clientsApi.list(params)
      .then((r) => { setClients(r.data); setTotal(r.total); setTotalPages(r.total_pages) })
      .catch(() => {})
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
        {/* ── Mobile card list ── */}
        <div className="block md:hidden">
          {loading ? (
            <div className="py-10 text-center text-gray-400 text-sm">Loading…</div>
          ) : clients.length === 0 ? (
            <div className="py-10 text-center text-gray-400 text-sm">No clients found</div>
          ) : (
            <div className="mobile-card-list">
              {clients.map((c) => (
                <div key={c.id} className="mobile-card-item">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="mobile-card-title">{c.name}</p>
                      <p className="mobile-card-sub">{c.phone}</p>
                      {c.email && <p className="mobile-card-sub">{c.email}</p>}
                    </div>
                    <span className={`shrink-0 ${c.status === 'active' ? 'badge-green' : 'badge-gray'} badge`}>{c.status}</span>
                  </div>
                  <div className="mobile-card-meta">
                    {c.branch_code && (
                      <span className="text-xs text-gray-500 bg-gray-100 rounded px-1.5 py-0.5">{c.branch_code}</span>
                    )}
                    <span className="text-xs text-gray-500">{c.pet_count ?? 0} pet{(c.pet_count ?? 0) !== 1 ? 's' : ''}</span>
                    {c.wallet_balance !== 0 && (
                      <span className="text-xs text-gray-500">Wallet: {fmt.inr(c.wallet_balance)}</span>
                    )}
                  </div>
                  <div className="mobile-card-actions">
                    <Link to={`/clients/${c.id}`} className="btn btn-primary btn-sm flex-1 justify-center">View</Link>
                    <Link to={`/clients/${c.id}/edit`} className="btn btn-secondary btn-sm flex-1 justify-center">Edit</Link>
                    {c.email && (
                      <>
                        <a
                          href={`${(window as any).OPB?.siteUrl ?? ''}/my-pets/?preview_client=${c.id}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="btn btn-secondary btn-sm"
                          title="Staff preview of client portal"
                        >👁</a>
                        <button
                          className="btn btn-secondary btn-sm"
                          title="Send My Pets access via WhatsApp"
                          onClick={(e) => {
                            e.stopPropagation()
                            const myPetsUrl = `${(window as any).OPB?.siteUrl ?? ''}/my-pets/`
                            open(c.phone, clientPortalMessage(c, myPetsUrl))
                          }}
                        >💬</button>
                      </>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* ── Desktop table ── */}
        <div className="hidden md:block">
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
                    <td className="font-medium text-blue-600 hover:underline whitespace-nowrap">{c.name}</td>
                    <td className="whitespace-nowrap">{c.phone}</td>
                    <td className="whitespace-nowrap">{c.email ?? '—'}</td>
                    <td className="whitespace-nowrap">{c.branch_code ?? '—'}</td>
                    <td>{c.pet_count ?? 0}</td>
                    <td className="whitespace-nowrap">{c.wallet_balance !== 0 ? fmt.inr(c.wallet_balance) : '—'}</td>
                    <td><span className={c.status === 'active' ? 'badge-green' : 'badge-gray'}>{c.status}</span></td>
                    <td><Link to={`/clients/${c.id}`} className="text-blue-600 hover:underline text-xs">View</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <Pagination page={page} totalPages={totalPages} total={total} perPage={perPage} onPage={(p) => { setPage(p); load(p) }} />
      </div>
    </div>
  )
}
