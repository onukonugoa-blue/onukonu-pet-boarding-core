import { useEffect, useState } from 'react'
import { expenseCategoriesApi } from '../../api/expenseCategories'
import type { ExpenseCategory } from '../../api/expenseCategories'
import Modal from '../../components/Modal'

const EMPTY = { name: '', sort_order: 0 }

export default function ExpenseCategories() {
  const [categories, setCategories] = useState<ExpenseCategory[]>([])
  const [loading, setLoading]       = useState(true)
  const [showArchived, setShowArchived] = useState(false)
  const [modal, setModal]           = useState(false)
  const [editing, setEditing]       = useState<ExpenseCategory | null>(null)
  const [form, setForm]             = useState<{ name: string; sort_order: number }>(EMPTY)
  const [saving, setSaving]         = useState(false)

  const load = () => {
    setLoading(true)
    expenseCategoriesApi.list(showArchived)
      .then(setCategories)
      .catch(() => {})
      .finally(() => setLoading(false))
  }

  useEffect(load, [showArchived])

  const openNew  = () => { setEditing(null); setForm(EMPTY); setModal(true) }
  const openEdit = (c: ExpenseCategory) => { setEditing(c); setForm({ name: c.name, sort_order: c.sort_order }); setModal(true) }
  const set      = (k: keyof typeof EMPTY, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editing) {
        await expenseCategoriesApi.update(editing.id, form)
      } else {
        await expenseCategoriesApi.create(form)
      }
      setModal(false)
      load()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleArchive = async (id: number, name: string) => {
    if (!confirm(`Archive category "${name}"? It will no longer appear in the Add Expense form. Existing expense records are unaffected.`)) return
    try {
      await expenseCategoriesApi.archive(id)
      load()
    } catch (e: any) { alert(e.message) }
  }

  const handleRestore = async (id: number) => {
    try {
      await expenseCategoriesApi.update(id, { is_active: 1 })
      load()
    } catch (e: any) { alert(e.message) }
  }

  const active   = categories.filter((c) => c.is_active)
  const archived = categories.filter((c) => !c.is_active)

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Expense Categories</h1>
          <p className="text-sm text-gray-500 mt-0.5">Manage categories available when adding expenses</p>
        </div>
        <button onClick={openNew} className="btn-primary">+ Add Category</button>
      </div>

      <div className="card">
        <div className="flex items-center justify-between mb-3">
          <p className="text-sm font-medium text-gray-700">{active.length} active {active.length === 1 ? 'category' : 'categories'}</p>
          <label className="flex items-center gap-2 text-sm text-gray-500 cursor-pointer">
            <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
            Show archived
          </label>
        </div>

        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Sort Order</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={4} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : categories.length === 0 ? (
                <tr><td colSpan={4} className="text-center py-8 text-gray-400">No categories found</td></tr>
              ) : categories.map((c) => (
                <tr key={c.id} className={!c.is_active ? 'opacity-50' : ''}>
                  <td className="font-medium">{c.name}</td>
                  <td className="text-gray-500">{c.sort_order}</td>
                  <td>
                    {c.is_active
                      ? <span className="badge badge-green text-xs">Active</span>
                      : <span className="badge badge-gray text-xs">Archived</span>
                    }
                  </td>
                  <td>
                    <div className="flex gap-2">
                      <button onClick={() => openEdit(c)} className="text-blue-600 text-xs hover:underline">Rename</button>
                      {c.is_active
                        ? <button onClick={() => handleArchive(c.id, c.name)} className="text-amber-600 text-xs hover:underline">Archive</button>
                        : <button onClick={() => handleRestore(c.id)} className="text-green-600 text-xs hover:underline">Restore</button>
                      }
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {!showArchived && archived.length > 0 && (
          <p className="text-xs text-gray-400 mt-3">{archived.length} archived {archived.length === 1 ? 'category' : 'categories'} hidden — enable "Show archived" to view.</p>
        )}
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title={editing ? 'Rename Category' : 'Add Category'}
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="cat-form" type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : 'Save'}</button></>}>
        <form id="cat-form" onSubmit={handleSave} className="space-y-3">
          <div className="form-group">
            <label className="form-label">Name *</label>
            <input className="form-input" required value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="e.g. Utilities" />
          </div>
          <div className="form-group">
            <label className="form-label">Sort Order</label>
            <input className="form-input" type="number" value={form.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))} />
            <p className="text-xs text-gray-400 mt-1">Lower numbers appear first</p>
          </div>
          {editing && (
            <p className="text-xs text-amber-600 bg-amber-50 rounded p-2">
              Renaming affects the add expense form going forward. Existing expense records will retain the original category name.
            </p>
          )}
        </form>
      </Modal>
    </div>
  )
}
