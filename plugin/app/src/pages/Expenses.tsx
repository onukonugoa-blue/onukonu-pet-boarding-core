import { useEffect, useState } from 'react'
import { expensesApi } from '../api/expenses'
import type { Expense } from '../api/expenses'
import { useBranchStore } from '../store/branch'
import { fmt } from '../api/client'
import Modal from '../components/Modal'
import Pagination from '../components/Pagination'

const CATEGORIES = ['Food','Medical','Grooming','Maintenance','Salary','Transport','Marketing','Other']
const MODES: Expense['mode'][] = ['Cash','UPI','Other']

const EMPTY: Partial<Expense> = { description: '', amount: 0, mode: 'Cash', category: 'Other', notes: '' }

export default function Expenses() {
  const [expenses, setExpenses] = useState<Expense[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [totalAmount, setTotalAmount] = useState(0)
  const [page, setPage] = useState(1)
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [category, setCategory] = useState('')
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<Partial<Expense>>(EMPTY)
  const [saving, setSaving] = useState(false)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const branches = useBranchStore((s) => s.branches)
  const perPage = 25

  const load = (p = 1) => {
    setLoading(true)
    const params: Record<string, unknown> = { page: p, per_page: perPage }
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    if (category) params.category = category
    if (activeBranchId) params.branch_id = activeBranchId
    expensesApi.list(params)
      .then((r) => { setExpenses(r.data); setTotal(r.total); setTotalPages(r.total_pages); setTotalAmount(r.total_amount) })
      .catch(() => {})
      .finally(() => setLoading(false))
  }

  useEffect(() => { load(1); setPage(1) }, [activeBranchId])

  const set = (k: keyof Expense, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await expensesApi.create({ ...form, branch_id: activeBranchId || form.branch_id })
      setModal(false)
      setForm(EMPTY)
      load(1); setPage(1)
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this expense?')) return
    await expensesApi.delete(id)
    load(page)
  }

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Expenses</h1>
          {totalAmount > 0 && <p className="text-sm text-gray-500 mt-0.5">Showing total: {fmt.inr(totalAmount)}</p>}
        </div>
        <button onClick={() => { setForm(EMPTY); setModal(true) }} className="btn-primary">+ Add Expense</button>
      </div>

      <div className="card mb-4">
        <div className="flex flex-wrap gap-2">
          <input className="form-input w-36" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          <input className="form-input w-36" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          <select className="form-select w-36" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">All Categories</option>
            {CATEGORIES.map((c) => <option key={c}>{c}</option>)}
          </select>
          <button onClick={() => { setPage(1); load(1) }} className="btn-primary">Filter</button>
          <button onClick={() => { setDateFrom(''); setDateTo(''); setCategory(''); setPage(1); load(1) }} className="btn-secondary">Clear</button>
        </div>
      </div>

      <div className="card">
        {/* ── Mobile card list ── */}
        <div className="block md:hidden">
          {loading ? (
            <div className="py-10 text-center text-gray-400 text-sm">Loading…</div>
          ) : expenses.length === 0 ? (
            <div className="py-10 text-center text-gray-400 text-sm">No expenses found</div>
          ) : (
            <div className="mobile-card-list">
              {expenses.map((e) => (
                <div key={e.id} className="mobile-card-item">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="mobile-card-title">{e.description}</p>
                      <p className="mobile-card-sub">{fmt.date(e.expense_at)} · {e.mode}</p>
                    </div>
                    <span className="shrink-0 font-semibold text-sm text-gray-800">{fmt.inr(e.amount)}</span>
                  </div>
                  <div className="mobile-card-meta">
                    <span className="badge badge-gray">{e.category}</span>
                    {e.branch_name && (
                      <span className="text-xs text-gray-500 bg-gray-100 rounded px-1.5 py-0.5">{e.branch_name}</span>
                    )}
                  </div>
                  <div className="mobile-card-actions">
                    <button
                      onClick={() => handleDelete(e.id)}
                      className="btn btn-danger btn-sm flex-1 justify-center"
                    >
                      Delete
                    </button>
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
              <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Mode</th><th>Branch</th><th>Amount</th><th></th></tr></thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {loading ? (
                  <tr><td colSpan={7} className="text-center py-8 text-gray-400">Loading…</td></tr>
                ) : expenses.length === 0 ? (
                  <tr><td colSpan={7} className="text-center py-8 text-gray-400">No expenses found</td></tr>
                ) : expenses.map((e) => (
                  <tr key={e.id}>
                    <td className="whitespace-nowrap">{fmt.date(e.expense_at)}</td>
                    <td>{e.description}</td>
                    <td><span className="badge-gray">{e.category}</span></td>
                    <td>{e.mode}</td>
                    <td className="whitespace-nowrap">{e.branch_name}</td>
                    <td className="font-medium whitespace-nowrap">{fmt.inr(e.amount)}</td>
                    <td><button onClick={() => handleDelete(e.id)} className="text-red-500 hover:underline text-xs">Delete</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <Pagination page={page} totalPages={totalPages} total={total} perPage={perPage} onPage={(p) => { setPage(p); load(p) }} />
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title="Add Expense"
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="exp-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="exp-form" onSubmit={handleSave} className="space-y-3">
          <div className="form-group"><label className="form-label">Description *</label><input className="form-input" required value={form.description??''} onChange={(e) => set('description',e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="form-group"><label className="form-label">Amount *</label><input className="form-input" type="number" step="0.01" required value={form.amount??''} onChange={(e) => set('amount',Number(e.target.value))} /></div>
            <div className="form-group"><label className="form-label">Mode</label><select className="form-select" value={form.mode??'Cash'} onChange={(e) => set('mode',e.target.value)}>{MODES.map((m) => <option key={m}>{m}</option>)}</select></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="form-group"><label className="form-label">Category</label><select className="form-select" value={form.category??''} onChange={(e) => set('category',e.target.value)}><option value="">Select…</option>{CATEGORIES.map((c) => <option key={c}>{c}</option>)}</select></div>
            <div className="form-group"><label className="form-label">Date</label><input className="form-input" type="date" value={form.expense_at?.slice(0,10)??''} onChange={(e) => set('expense_at',e.target.value)} /></div>
          </div>
          {!activeBranchId && (
            <div className="form-group"><label className="form-label">Branch</label><select className="form-select" value={form.branch_id??0} onChange={(e) => set('branch_id',Number(e.target.value))}><option value={0}>Select…</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></div>
          )}
          <div className="form-group"><label className="form-label">Notes</label><textarea className="form-input" rows={2} value={form.notes??''} onChange={(e) => set('notes',e.target.value)} /></div>
        </form>
      </Modal>
    </div>
  )
}
