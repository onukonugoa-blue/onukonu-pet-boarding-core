import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { inquiriesApi, STATUS_LABELS, STATUS_COLORS } from '../../api/inquiries'
import type { Inquiry, InquiryStatus } from '../../api/inquiries'
import { fmt } from '../../api/client'
import Pagination from '../../components/Pagination'

const STATUSES: InquiryStatus[] = [
  'NEW', 'CONTACTED', 'ONBOARDING_SENT', 'ONBOARDING_COMPLETED',
  'READY_FOR_REVIEW', 'CONVERTED', 'REJECTED', 'ARCHIVED',
]

export default function InquiryList() {
  const navigate = useNavigate()
  const [inquiries, setInquiries] = useState<Inquiry[]>([])
  const [total, setTotal]         = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [page, setPage]           = useState(1)
  const [loading, setLoading]     = useState(true)
  const [search, setSearch]       = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, unknown> = { page, per_page: 30 }
      if (search) params.search = search
      if (statusFilter) params.status = statusFilter
      const res = await inquiriesApi.list(params)
      setInquiries(res.data)
      setTotal(res.total)
      setTotalPages(res.total_pages)
    } catch {
      // silent
    } finally {
      setLoading(false)
    }
  }, [page, search, statusFilter])

  useEffect(() => { load() }, [load])

  const handleSearch = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setPage(1)
    load()
  }

  return (
    <div className="p-4 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-blue-900">Inquiries</h1>
          <p className="text-sm text-gray-500 mt-0.5">{total} total</p>
        </div>
        <a
          href={`${window.location.origin}/opb-inquiry/`}
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm bg-blue-50 text-blue-700 border border-blue-200 rounded-lg px-3 py-1.5 hover:bg-blue-100 transition-colors"
        >
          🔗 Public Inquiry Form
        </a>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-3 mb-4 flex flex-wrap gap-3 items-center">
        <form onSubmit={handleSearch} className="flex gap-2 flex-1 min-w-48">
          <input
            type="text"
            placeholder="Search name, phone, email, pet…"
            value={search}
            onChange={e => setSearch(e.target.value)}
            className="form-input flex-1 text-sm"
          />
          <button type="submit" className="px-3 py-1.5 bg-blue-800 text-white text-sm rounded-lg hover:bg-blue-700">
            Search
          </button>
        </form>
        <select
          value={statusFilter}
          onChange={e => { setStatusFilter(e.target.value); setPage(1) }}
          className="form-input text-sm w-48"
        >
          <option value="">All Statuses</option>
          {STATUSES.map(s => (
            <option key={s} value={s}>{STATUS_LABELS[s]}</option>
          ))}
        </select>
        {(search || statusFilter) && (
          <button
            onClick={() => { setSearch(''); setStatusFilter(''); setPage(1) }}
            className="text-sm text-gray-500 hover:text-gray-700 underline"
          >
            Clear
          </button>
        )}
      </div>

      {/* Status quick-filters */}
      <div className="flex flex-wrap gap-2 mb-4">
        {(['NEW', 'READY_FOR_REVIEW', 'ONBOARDING_COMPLETED', 'ONBOARDING_SENT'] as InquiryStatus[]).map(s => (
          <button
            key={s}
            onClick={() => { setStatusFilter(s === statusFilter ? '' : s); setPage(1) }}
            className={`text-xs px-3 py-1 rounded-full border font-medium transition-colors ${
              statusFilter === s
                ? STATUS_COLORS[s] + ' border-current'
                : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'
            }`}
          >
            {STATUS_LABELS[s]}
          </button>
        ))}
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-gray-400">Loading…</div>
        ) : inquiries.length === 0 ? (
          <div className="p-12 text-center">
            <p className="text-4xl mb-3">📋</p>
            <p className="text-gray-500 font-medium">No inquiries found</p>
            <p className="text-sm text-gray-400 mt-1">
              {search || statusFilter ? 'Try adjusting your filters.' : 'Share your public inquiry form to get started.'}
            </p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Inquiry</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide hidden sm:table-cell">Pet</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide hidden md:table-cell">Branch</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide hidden lg:table-cell">Received</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {inquiries.map(inq => (
                <tr
                  key={inq.id}
                  onClick={() => navigate(`/inquiries/${inq.id}`)}
                  className="hover:bg-blue-50 cursor-pointer transition-colors"
                >
                  <td className="px-4 py-3">
                    <div className="flex items-start gap-2">
                      {inq.existing_client_id && (
                        <span title="Existing client detected" className="text-amber-500 text-xs mt-0.5">⚠</span>
                      )}
                      <div>
                        <div className="font-semibold text-gray-900">{inq.owner_name}</div>
                        <div className="text-gray-500 text-xs">{inq.phone}</div>
                        {inq.email && <div className="text-gray-400 text-xs">{inq.email}</div>}
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 hidden sm:table-cell text-gray-600">
                    {inq.pet_name && (
                      <div>
                        <div className="font-medium">{inq.pet_name}</div>
                        {inq.pet_type && <div className="text-xs text-gray-400">{inq.pet_type}</div>}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3 hidden md:table-cell text-gray-500 text-xs">
                    {inq.branch_name || '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-semibold ${STATUS_COLORS[inq.status]}`}>
                      {STATUS_LABELS[inq.status]}
                    </span>
                    {(inq.note_count ?? 0) > 0 && (
                      <span className="ml-1.5 text-xs text-gray-400">💬 {inq.note_count}</span>
                    )}
                    {(inq.doc_count ?? 0) > 0 && (
                      <span className="ml-1.5 text-xs text-gray-400">📎 {inq.doc_count}</span>
                    )}
                  </td>
                  <td className="px-4 py-3 hidden lg:table-cell text-gray-400 text-xs">
                    {fmt.date(inq.created_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {totalPages > 1 && (
        <div className="mt-4">
          <Pagination page={page} totalPages={totalPages} total={total} perPage={30} onPage={setPage} />
        </div>
      )}
    </div>
  )
}
