import { useState, useEffect, useCallback, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  dataManagementApi,
  type DmClient,
  type DmPet,
  type DmBooking,
  type DmInquiry,
  type DmView,
} from '../../api/dataManagement'
import { ApiError } from '../../api/client'

// ── Shared helpers ────────────────────────────────────────────────────────────

type Tab = 'clients' | 'pets' | 'bookings' | 'inquiries'

const VIEW_LABELS: Record<string, string> = {
  active: 'Active',
  archived: 'Archived',
  cancelled: 'Cancelled',
  all: 'All',
}

function ViewToggle({
  value,
  options,
  onChange,
}: {
  value: string
  options: string[]
  onChange: (v: string) => void
}) {
  return (
    <div className="flex gap-1 flex-wrap">
      {options.map((opt) => (
        <button
          key={opt}
          onClick={() => onChange(opt)}
          className={`px-3 py-1.5 rounded text-sm font-medium transition-colors ${
            value === opt
              ? 'bg-blue-700 text-white'
              : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50'
          }`}
        >
          {VIEW_LABELS[opt] ?? opt}
        </button>
      ))}
    </div>
  )
}

function SearchBar({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
}) {
  return (
    <input
      type="search"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder ?? 'Search…'}
      className="border border-gray-300 rounded px-3 py-1.5 text-sm w-full sm:w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
    />
  )
}

function Pagination({
  page,
  totalPages,
  total,
  onPage,
}: {
  page: number
  totalPages: number
  total: number
  onPage: (p: number) => void
}) {
  if (totalPages <= 1) return null
  return (
    <div className="flex items-center justify-between mt-4 text-sm text-gray-600">
      <span>{total} total</span>
      <div className="flex gap-2">
        <button
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
          className="px-3 py-1.5 border border-gray-300 rounded disabled:opacity-40 hover:bg-gray-50"
        >
          Prev
        </button>
        <span className="px-2 py-1.5">
          {page} / {totalPages}
        </span>
        <button
          disabled={page >= totalPages}
          onClick={() => onPage(page + 1)}
          className="px-3 py-1.5 border border-gray-300 disabled:opacity-40 rounded hover:bg-gray-50"
        >
          Next
        </button>
      </div>
    </div>
  )
}

function ActionBtn({
  label,
  variant,
  onClick,
  disabled,
}: {
  label: string
  variant: 'archive' | 'restore' | 'cancel'
  onClick: () => void
  disabled?: boolean
}) {
  const base = 'px-2 py-1 rounded text-xs font-medium transition-colors disabled:opacity-40'
  const styles = {
    archive: `${base} bg-yellow-100 text-yellow-800 hover:bg-yellow-200`,
    cancel:  `${base} bg-red-100 text-red-700 hover:bg-red-200`,
    restore: `${base} bg-green-100 text-green-800 hover:bg-green-200`,
  }
  return (
    <button className={styles[variant]} onClick={onClick} disabled={disabled}>
      {label}
    </button>
  )
}

function StatusBadge({ status }: { status: string }) {
  const lower = status.toLowerCase()
  let cls = 'px-2 py-0.5 rounded-full text-xs font-medium '
  if (lower === 'active' || lower === 'new' || lower === 'ready_for_review') cls += 'bg-green-100 text-green-800'
  else if (lower === 'archived' || lower === 'cancelled') cls += 'bg-gray-100 text-gray-600'
  else if (lower === 'rejected') cls += 'bg-red-100 text-red-700'
  else if (lower === 'converted') cls += 'bg-blue-100 text-blue-700'
  else cls += 'bg-yellow-100 text-yellow-800'
  return <span className={cls}>{status}</span>
}

function EmptyRow({ cols }: { cols: number }) {
  return (
    <tr>
      <td colSpan={cols} className="py-10 text-center text-gray-400 text-sm">
        No records found.
      </td>
    </tr>
  )
}

function ErrMsg({ msg }: { msg: string }) {
  return (
    <div className="rounded border border-red-200 bg-red-50 text-red-700 text-sm p-3 my-3">
      {msg}
    </div>
  )
}

// ── Future-bookings warning modal ─────────────────────────────────────────────

function FutureBookingsWarningModal({
  client,
  count,
  onContinue,
  onClose,
}: {
  client: DmClient
  count: number
  onContinue: () => void
  onClose: () => void
}) {
  const navigate = useNavigate()
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <h3 className="text-base font-semibold text-gray-800 mb-1">Future Bookings Exist</h3>
        <p className="text-sm text-gray-600 mb-4">
          <strong>{client.name}</strong> has{' '}
          <strong>{count} future active {count === 1 ? 'booking' : 'bookings'}</strong>.
          Future bookings will continue to occupy kennel capacity and appear in operational
          schedules until they are individually cancelled.
        </p>
        <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-5">
          Please cancel future bookings before archiving to keep operational views accurate.
        </p>
        <div className="flex flex-wrap justify-end gap-3">
          <button
            onClick={onClose}
            className="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={() => { onClose(); navigate(`/bookings?search=${encodeURIComponent(client.name)}`) }}
            className="px-4 py-2 border border-blue-300 rounded text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100"
          >
            View Bookings
          </button>
          <button
            onClick={onContinue}
            className="px-4 py-2 rounded text-sm font-medium text-white bg-yellow-600 hover:bg-yellow-700"
          >
            Continue Archive
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Archive-client modal ──────────────────────────────────────────────────────

function ArchiveClientModal({
  client,
  onConfirm,
  onClose,
}: {
  client: DmClient
  onConfirm: (reason: string) => void
  onClose: () => void
}) {
  const [reason, setReason] = useState('')
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <h3 className="text-base font-semibold text-gray-800 mb-1">Archive Client</h3>
        <p className="text-sm text-gray-600 mb-4">
          Archive <strong>{client.name}</strong>? They will be hidden from all operational workflows
          but their history and invoices will be preserved.
        </p>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Reason <span className="text-gray-400 font-normal">(optional)</span>
        </label>
        <textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          placeholder="e.g. Client requested removal, pet deceased…"
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
        />
        <div className="flex justify-end gap-3 mt-5">
          <button
            onClick={onClose}
            className="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={() => onConfirm(reason)}
            className="px-4 py-2 rounded text-sm font-medium text-white bg-yellow-600 hover:bg-yellow-700"
          >
            Archive Client
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Clients tab ───────────────────────────────────────────────────────────────

function ClientsTab() {
  const [view, setView] = useState<DmView>('active')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<DmClient[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [archiveTarget, setArchiveTarget]   = useState<DmClient | null>(null)
  const [archiveWarning, setArchiveWarning] = useState<{ client: DmClient; count: number } | null>(null)
  const [acting, setActing] = useState<number | null>(null)
  const debounce = useRef<number | undefined>()

  const load = useCallback(() => {
    setLoading(true)
    setErr('')
    dataManagementApi
      .listClients({ view, search, page, per_page: 30 })
      .then((res) => {
        setRows(res.data)
        setTotal(res.total)
        setTotalPages(res.total_pages)
      })
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load clients.'))
      .finally(() => setLoading(false))
  }, [view, search, page])

  useEffect(() => {
    setPage(1)
  }, [view, search])

  useEffect(() => {
    clearTimeout(debounce.current)
    debounce.current = window.setTimeout(load, search ? 300 : 0)
    return () => clearTimeout(debounce.current)
  }, [load])

  /**
   * Check for future active bookings before showing the archive modal.
   * If any exist, show a warning first; the operator can still proceed.
   */
  const initiateArchive = async (c: DmClient) => {
    try {
      const today = new Date().toISOString().slice(0, 10)
      const res   = await dataManagementApi.listBookings({ view: 'active', client_id: c.id, per_page: 100 })
      const futureCount = res.data.filter((b) => (b.check_out_date ?? '') >= today).length
      if (futureCount > 0) {
        setArchiveWarning({ client: c, count: futureCount })
        return
      }
    } catch {
      // If the pre-check fails, proceed to archive modal as normal
    }
    setArchiveTarget(c)
  }

  const doArchive = async (reason: string) => {
    if (!archiveTarget) return
    setActing(archiveTarget.id)
    setArchiveTarget(null)
    try {
      await dataManagementApi.archiveClient(archiveTarget.id, reason)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Archive failed.')
    } finally {
      setActing(null)
    }
  }

  const doRestore = async (id: number) => {
    if (!confirm('Restore this client to active status?')) return
    setActing(id)
    try {
      await dataManagementApi.restoreClient(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Restore failed.')
    } finally {
      setActing(null)
    }
  }

  return (
    <div>
      <div className="flex flex-wrap gap-3 items-center justify-between mb-4">
        <ViewToggle value={view} options={['active', 'archived', 'all']} onChange={(v) => setView(v as DmView)} />
        <SearchBar value={search} onChange={setSearch} placeholder="Name, phone, email…" />
      </div>

      {err && <ErrMsg msg={err} />}

      <div className="overflow-x-auto rounded border border-gray-200">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Client</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Contact</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Branch</th>
              <th className="px-4 py-2.5 text-center font-medium text-gray-600">Pets</th>
              <th className="px-4 py-2.5 text-center font-medium text-gray-600">Bookings</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading ? (
              <tr><td colSpan={7} className="py-10 text-center text-gray-400 text-sm">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <EmptyRow cols={7} />
            ) : rows.map((c) => (
              <tr key={c.id} className="hover:bg-gray-50">
                <td className="px-4 py-2.5">
                  <Link to={`/clients/${c.id}`} className="font-medium text-blue-700 hover:underline">
                    {c.name}
                  </Link>
                  {c.archive_reason && (
                    <p className="text-xs text-gray-400 mt-0.5 max-w-xs truncate" title={c.archive_reason}>
                      {c.archive_reason}
                    </p>
                  )}
                </td>
                <td className="px-4 py-2.5 text-gray-600">
                  <div>{c.phone}</div>
                  {c.email && <div className="text-xs text-gray-400">{c.email}</div>}
                </td>
                <td className="px-4 py-2.5 text-gray-600">{c.branch_name ?? '—'}</td>
                <td className="px-4 py-2.5 text-center text-gray-700">{c.pet_count}</td>
                <td className="px-4 py-2.5 text-center text-gray-700">{c.booking_count}</td>
                <td className="px-4 py-2.5"><StatusBadge status={c.status} /></td>
                <td className="px-4 py-2.5">
                  {c.status === 'active' ? (
                    <ActionBtn
                      label="Archive"
                      variant="archive"
                      disabled={acting === c.id}
                      onClick={() => initiateArchive(c)}
                    />
                  ) : (
                    <ActionBtn
                      label="Restore"
                      variant="restore"
                      disabled={acting === c.id}
                      onClick={() => doRestore(c.id)}
                    />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Pagination page={page} totalPages={totalPages} total={total} onPage={setPage} />

      {archiveWarning && (
        <FutureBookingsWarningModal
          client={archiveWarning.client}
          count={archiveWarning.count}
          onContinue={() => { setArchiveTarget(archiveWarning.client); setArchiveWarning(null) }}
          onClose={() => setArchiveWarning(null)}
        />
      )}
      {archiveTarget && (
        <ArchiveClientModal
          client={archiveTarget}
          onConfirm={doArchive}
          onClose={() => setArchiveTarget(null)}
        />
      )}
    </div>
  )
}

// ── Pets tab ──────────────────────────────────────────────────────────────────

function PetsTab() {
  const [view, setView] = useState<DmView>('active')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<DmPet[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [acting, setActing] = useState<number | null>(null)
  const debounce = useRef<number | undefined>()

  const load = useCallback(() => {
    setLoading(true)
    setErr('')
    dataManagementApi
      .listPets({ view, search, page, per_page: 30 })
      .then((res) => {
        setRows(res.data)
        setTotal(res.total)
        setTotalPages(res.total_pages)
      })
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load pets.'))
      .finally(() => setLoading(false))
  }, [view, search, page])

  useEffect(() => { setPage(1) }, [view, search])
  useEffect(() => {
    clearTimeout(debounce.current)
    debounce.current = window.setTimeout(load, search ? 300 : 0)
    return () => clearTimeout(debounce.current)
  }, [load])

  const doArchive = async (id: number, name: string) => {
    if (!confirm(`Archive ${name}? Historical stays and bookings will be preserved.`)) return
    setActing(id)
    try {
      await dataManagementApi.archivePet(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Archive failed.')
    } finally {
      setActing(null)
    }
  }

  const doRestore = async (id: number, name: string) => {
    if (!confirm(`Restore ${name} to active status?`)) return
    setActing(id)
    try {
      await dataManagementApi.restorePet(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Restore failed.')
    } finally {
      setActing(null)
    }
  }

  return (
    <div>
      <div className="flex flex-wrap gap-3 items-center justify-between mb-4">
        <ViewToggle value={view} options={['active', 'archived', 'all']} onChange={(v) => setView(v as DmView)} />
        <SearchBar value={search} onChange={setSearch} placeholder="Pet name, owner, phone…" />
      </div>

      {err && <ErrMsg msg={err} />}

      <div className="overflow-x-auto rounded border border-gray-200">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Pet</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Type / Breed</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Owner</th>
              <th className="px-4 py-2.5 text-center font-medium text-gray-600">Stays</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading ? (
              <tr><td colSpan={6} className="py-10 text-center text-gray-400 text-sm">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <EmptyRow cols={6} />
            ) : rows.map((p) => (
              <tr key={p.id} className="hover:bg-gray-50">
                <td className="px-4 py-2.5">
                  <Link to={`/pets/${p.id}`} className="font-medium text-blue-700 hover:underline">
                    {p.name}
                  </Link>
                </td>
                <td className="px-4 py-2.5 text-gray-600">
                  <span>{p.pet_type}</span>
                  {p.breed && <span className="text-gray-400"> · {p.breed}</span>}
                </td>
                <td className="px-4 py-2.5 text-gray-600">
                  <Link to={`/clients/${p.client_id}`} className="text-blue-700 hover:underline">
                    {p.client_name}
                  </Link>
                  <div className="text-xs text-gray-400">{p.client_phone}</div>
                </td>
                <td className="px-4 py-2.5 text-center text-gray-700">{p.stay_count}</td>
                <td className="px-4 py-2.5">
                  <StatusBadge status={String(p.is_active) === '1' ? 'active' : 'archived'} />
                </td>
                <td className="px-4 py-2.5">
                  {String(p.is_active) === '1' ? (
                    <ActionBtn
                      label="Archive"
                      variant="archive"
                      disabled={acting === p.id}
                      onClick={() => doArchive(p.id, p.name)}
                    />
                  ) : (
                    <ActionBtn
                      label="Restore"
                      variant="restore"
                      disabled={acting === p.id}
                      onClick={() => doRestore(p.id, p.name)}
                    />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Pagination page={page} totalPages={totalPages} total={total} onPage={setPage} />
    </div>
  )
}

// ── Bookings tab ──────────────────────────────────────────────────────────────

function BookingsTab() {
  const [view, setView] = useState('active')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<DmBooking[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [acting, setActing] = useState<number | null>(null)
  const debounce = useRef<number | undefined>()

  const load = useCallback(() => {
    setLoading(true)
    setErr('')
    dataManagementApi
      .listBookings({ view, search, page, per_page: 30 })
      .then((res) => {
        setRows(res.data)
        setTotal(res.total)
        setTotalPages(res.total_pages)
      })
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load bookings.'))
      .finally(() => setLoading(false))
  }, [view, search, page])

  useEffect(() => { setPage(1) }, [view, search])
  useEffect(() => {
    clearTimeout(debounce.current)
    debounce.current = window.setTimeout(load, search ? 300 : 0)
    return () => clearTimeout(debounce.current)
  }, [load])

  const doCancel = async (id: number) => {
    if (!confirm(`Cancel booking #${id}? Stays and invoices are preserved. This action can be reversed.`)) return
    setActing(id)
    try {
      await dataManagementApi.cancelBooking(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Cancel failed.')
    } finally {
      setActing(null)
    }
  }

  const doRestore = async (id: number) => {
    if (!confirm(`Restore booking #${id} to Active status?`)) return
    setActing(id)
    try {
      await dataManagementApi.restoreBooking(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Restore failed.')
    } finally {
      setActing(null)
    }
  }

  return (
    <div>
      <div className="flex flex-wrap gap-3 items-center justify-between mb-4">
        <ViewToggle value={view} options={['active', 'cancelled', 'all']} onChange={setView} />
        <SearchBar value={search} onChange={setSearch} placeholder="Client name, phone, booking #…" />
      </div>

      {err && <ErrMsg msg={err} />}

      <div className="overflow-x-auto rounded border border-gray-200">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">#</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Date</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Client</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Pets</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Branch</th>
              <th className="px-4 py-2.5 text-right font-medium text-gray-600">Amount</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading ? (
              <tr><td colSpan={8} className="py-10 text-center text-gray-400 text-sm">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <EmptyRow cols={8} />
            ) : rows.map((b) => (
              <tr key={b.id} className="hover:bg-gray-50">
                <td className="px-4 py-2.5">
                  <Link to={`/bookings/${b.id}`} className="font-medium text-blue-700 hover:underline">
                    #{b.id}
                  </Link>
                </td>
                <td className="px-4 py-2.5 text-gray-600 whitespace-nowrap">{b.booking_date}</td>
                <td className="px-4 py-2.5">
                  <Link to={`/clients/${b.client_id}`} className="text-blue-700 hover:underline">
                    {b.client_name}
                  </Link>
                  <div className="text-xs text-gray-400">{b.client_phone}</div>
                </td>
                <td className="px-4 py-2.5 text-gray-600 max-w-xs truncate">{b.pet_names ?? '—'}</td>
                <td className="px-4 py-2.5 text-gray-600">{b.branch_name ?? '—'}</td>
                <td className="px-4 py-2.5 text-right text-gray-700">
                  ₹{Number(b.total_billing_amount).toLocaleString('en-IN')}
                </td>
                <td className="px-4 py-2.5">
                  <div className="flex flex-col gap-1">
                    <StatusBadge status={b.status} />
                    <span className="text-xs text-gray-400">{b.payment_status}</span>
                  </div>
                </td>
                <td className="px-4 py-2.5">
                  {b.status !== 'Cancelled' ? (
                    <ActionBtn
                      label="Cancel"
                      variant="cancel"
                      disabled={acting === b.id}
                      onClick={() => doCancel(b.id)}
                    />
                  ) : (
                    <ActionBtn
                      label="Restore"
                      variant="restore"
                      disabled={acting === b.id}
                      onClick={() => doRestore(b.id)}
                    />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Pagination page={page} totalPages={totalPages} total={total} onPage={setPage} />
    </div>
  )
}

// ── Inquiries tab ─────────────────────────────────────────────────────────────

function InquiriesTab() {
  const [view, setView] = useState<DmView>('active')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<DmInquiry[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [acting, setActing] = useState<number | null>(null)
  const debounce = useRef<number | undefined>()

  const load = useCallback(() => {
    setLoading(true)
    setErr('')
    dataManagementApi
      .listInquiries({ view, search, page, per_page: 30 })
      .then((res) => {
        setRows(res.data)
        setTotal(res.total)
        setTotalPages(res.total_pages)
      })
      .catch((e) => setErr(e instanceof ApiError ? e.message : 'Failed to load inquiries.'))
      .finally(() => setLoading(false))
  }, [view, search, page])

  useEffect(() => { setPage(1) }, [view, search])
  useEffect(() => {
    clearTimeout(debounce.current)
    debounce.current = window.setTimeout(load, search ? 300 : 0)
    return () => clearTimeout(debounce.current)
  }, [load])

  const doArchive = async (id: number, name: string) => {
    if (!confirm(`Archive inquiry from ${name}? It will be hidden from standard queues.`)) return
    setActing(id)
    try {
      await dataManagementApi.archiveInquiry(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Archive failed.')
    } finally {
      setActing(null)
    }
  }

  const doRestore = async (id: number) => {
    if (!confirm('Restore this inquiry to New status?')) return
    setActing(id)
    try {
      await dataManagementApi.restoreInquiry(id)
      load()
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Restore failed.')
    } finally {
      setActing(null)
    }
  }

  const isArchived = (status: string) => status === 'ARCHIVED' || status === 'REJECTED'

  return (
    <div>
      <div className="flex flex-wrap gap-3 items-center justify-between mb-4">
        <ViewToggle value={view} options={['active', 'archived', 'all']} onChange={(v) => setView(v as DmView)} />
        <SearchBar value={search} onChange={setSearch} placeholder="Name, phone, email, pet…" />
      </div>

      {err && <ErrMsg msg={err} />}

      <div className="overflow-x-auto rounded border border-gray-200">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">#</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Owner</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Pet</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Branch</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Date</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading ? (
              <tr><td colSpan={7} className="py-10 text-center text-gray-400 text-sm">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <EmptyRow cols={7} />
            ) : rows.map((i) => (
              <tr key={i.id} className="hover:bg-gray-50">
                <td className="px-4 py-2.5">
                  <Link to={`/inquiries/${i.id}`} className="font-medium text-blue-700 hover:underline">
                    #{i.id}
                  </Link>
                </td>
                <td className="px-4 py-2.5 text-gray-700">
                  <div className="font-medium">{i.owner_name}</div>
                  <div className="text-xs text-gray-400">{i.phone}</div>
                </td>
                <td className="px-4 py-2.5 text-gray-600">
                  {i.pet_name ?? '—'}
                  {i.pet_type && <span className="text-gray-400"> · {i.pet_type}</span>}
                </td>
                <td className="px-4 py-2.5 text-gray-600">{i.branch_name ?? '—'}</td>
                <td className="px-4 py-2.5 text-gray-500 whitespace-nowrap text-xs">
                  {new Date(i.created_at).toLocaleDateString()}
                </td>
                <td className="px-4 py-2.5"><StatusBadge status={i.status} /></td>
                <td className="px-4 py-2.5">
                  {!isArchived(i.status) ? (
                    <ActionBtn
                      label="Archive"
                      variant="archive"
                      disabled={acting === i.id}
                      onClick={() => doArchive(i.id, i.owner_name)}
                    />
                  ) : (
                    <ActionBtn
                      label="Restore"
                      variant="restore"
                      disabled={acting === i.id}
                      onClick={() => doRestore(i.id)}
                    />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Pagination page={page} totalPages={totalPages} total={total} onPage={setPage} />
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

const TABS: { id: Tab; label: string; icon: string }[] = [
  { id: 'clients',   label: 'Clients',   icon: '👥' },
  { id: 'pets',      label: 'Pets',      icon: '🐾' },
  { id: 'bookings',  label: 'Bookings',  icon: '📋' },
  { id: 'inquiries', label: 'Inquiries', icon: '📩' },
]

const POLICY_NOTE = `Phase 1 — Archive and Restore only. Permanent deletion is not available.
All history, invoices, and financial records are fully preserved regardless of archive status.`

export default function DataManagement() {
  const [tab, setTab] = useState<Tab>('clients')

  return (
    <div className="max-w-7xl mx-auto px-4 py-6 space-y-5">
      {/* Header */}
      <div>
        <div className="flex items-center gap-2 mb-1">
          <span className="text-xl">🛡</span>
          <h1 className="text-xl font-bold text-gray-900">Data Management</h1>
          <span className="ml-2 px-2 py-0.5 rounded bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide">
            Super Admin
          </span>
        </div>
        <p className="text-sm text-gray-500">{POLICY_NOTE}</p>
      </div>

      {/* Tab bar */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-1 overflow-x-auto">
          {TABS.map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
                tab === t.id
                  ? 'border-blue-700 text-blue-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              <span>{t.icon}</span>
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      <div className="bg-white rounded-lg border border-gray-200 p-4">
        {tab === 'clients'   && <ClientsTab />}
        {tab === 'pets'      && <PetsTab />}
        {tab === 'bookings'  && <BookingsTab />}
        {tab === 'inquiries' && <InquiriesTab />}
      </div>
    </div>
  )
}
