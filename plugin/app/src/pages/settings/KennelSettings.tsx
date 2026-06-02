import { useEffect, useState } from 'react'
import { kennelsApi } from '../../api/kennels'
import type { Kennel } from '../../api/kennels'
import { branchesApi } from '../../api/branches'
import type { Branch } from '../../store/branch'
import Modal from '../../components/Modal'

const STATUS_OPTIONS = ['Available', 'Occupied', 'Maintenance', 'Blocked'] as const
const STATUS_COLORS: Record<string, string> = {
  Available:   'bg-green-100 text-green-800',
  Occupied:    'bg-blue-100 text-blue-800',
  Maintenance: 'bg-yellow-100 text-yellow-800',
  Blocked:     'bg-red-100 text-red-800',
}

interface FormState {
  branch_id: string
  code: string
  name: string
  status: string
  notes: string
}

const EMPTY_FORM: FormState = { branch_id: '', code: '', name: '', status: 'Available', notes: '' }

export default function KennelSettings() {
  const [kennels, setKennels]     = useState<Kennel[]>([])
  const [branches, setBranches]   = useState<Branch[]>([])
  const [loading, setLoading]     = useState(true)
  const [saving, setSaving]       = useState(false)
  const [error, setError]         = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing]     = useState<Kennel | null>(null)
  const [form, setForm]           = useState<FormState>(EMPTY_FORM)
  const [showDisabled, setShowDisabled] = useState(false)

  const load = () => {
    setLoading(true)
    Promise.all([kennelsApi.list(undefined, false), branchesApi.list()])
      .then(([ks, bs]) => { setKennels(ks); setBranches(bs) })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  const openCreate = () => {
    setEditing(null)
    setForm(EMPTY_FORM)
    setError('')
    setShowModal(true)
  }

  const openEdit = (k: Kennel) => {
    setEditing(k)
    setForm({ branch_id: String(k.branch_id), code: k.code, name: k.name, status: k.status, notes: k.notes ?? '' })
    setError('')
    setShowModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.branch_id || !form.code || !form.name) { setError('Branch, Code and Name are required'); return }
    setSaving(true); setError('')
    try {
      if (editing) {
        await kennelsApi.update(editing.id, { code: form.code, name: form.name, status: form.status as Kennel['status'], notes: form.notes })
      } else {
        await kennelsApi.create({ branch_id: Number(form.branch_id), code: form.code, name: form.name, status: form.status as Kennel['status'], notes: form.notes })
      }
      setShowModal(false)
      load()
    } catch (e: any) {
      setError(e.message ?? 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const handleDisable = async (k: Kennel) => {
    if (!confirm(`Disable kennel ${k.code} – ${k.name}? It will no longer appear for assignment.`)) return
    await kennelsApi.update(k.id, { is_active: 0 })
    load()
  }

  const handleEnable = async (k: Kennel) => {
    await kennelsApi.update(k.id, { is_active: 1 })
    load()
  }

  const handleStatusChange = async (k: Kennel, status: string) => {
    await kennelsApi.update(k.id, { status: status as Kennel['status'] })
    load()
  }

  const handleMoveUp = async (k: Kennel, branchKennels: Kennel[]) => {
    const idx = branchKennels.findIndex((x) => x.id === k.id)
    if (idx === 0) return
    const prev = branchKennels[idx - 1]
    await kennelsApi.reorder([
      { id: k.id, sort_order: prev.sort_order },
      { id: prev.id, sort_order: k.sort_order },
    ])
    load()
  }

  const handleMoveDown = async (k: Kennel, branchKennels: Kennel[]) => {
    const idx = branchKennels.findIndex((x) => x.id === k.id)
    if (idx === branchKennels.length - 1) return
    const next = branchKennels[idx + 1]
    await kennelsApi.reorder([
      { id: k.id, sort_order: next.sort_order },
      { id: next.id, sort_order: k.sort_order },
    ])
    load()
  }

  const visibleKennels = kennels.filter((k) => showDisabled ? true : k.is_active)

  const grouped = branches.reduce<Record<number, { branch: Branch; items: Kennel[] }>>((acc, b) => {
    acc[b.id] = { branch: b, items: visibleKennels.filter((k) => k.branch_id === b.id) }
    return acc
  }, {})

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">Kennels</h1>
          <p className="text-sm text-gray-500 mt-1">Manage kennel units by branch</p>
        </div>
        <div className="flex gap-2 items-center">
          <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" checked={showDisabled} onChange={(e) => setShowDisabled(e.target.checked)} />
            Show disabled
          </label>
          <button onClick={openCreate} className="btn-primary">+ Add Kennel</button>
        </div>
      </div>

      {error && !showModal && <div className="alert-error mb-4">{error}</div>}

      {Object.values(grouped).map(({ branch, items }) => (
        <div key={branch.id} className="card mb-4">
          <h2 className="font-semibold text-gray-800 mb-3 flex items-center gap-2">
            <span className="text-blue-600 font-mono text-sm bg-blue-50 px-2 py-0.5 rounded">{branch.code}</span>
            {branch.name}
            <span className="ml-auto text-xs text-gray-400 font-normal">{items.length} kennel{items.length !== 1 ? 's' : ''}</span>
          </h2>

          {items.length === 0 ? (
            <p className="text-sm text-gray-400 italic">No kennels for this branch yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100">
                    <th className="text-left py-2 px-2 text-xs font-semibold text-gray-500 w-20">Code</th>
                    <th className="text-left py-2 px-2 text-xs font-semibold text-gray-500">Name</th>
                    <th className="text-left py-2 px-2 text-xs font-semibold text-gray-500 w-36">Status</th>
                    <th className="text-left py-2 px-2 text-xs font-semibold text-gray-500">Notes</th>
                    <th className="text-right py-2 px-2 text-xs font-semibold text-gray-500 w-32">Order</th>
                    <th className="text-right py-2 px-2 text-xs font-semibold text-gray-500 w-28">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((k, idx) => (
                    <tr key={k.id} className={`border-b border-gray-50 hover:bg-gray-50 ${!k.is_active ? 'opacity-50' : ''}`}>
                      <td className="py-2 px-2 font-mono font-semibold text-gray-800">{k.code}</td>
                      <td className="py-2 px-2 text-gray-700">{k.name}</td>
                      <td className="py-2 px-2">
                        <select
                          value={k.status}
                          onChange={(e) => handleStatusChange(k, e.target.value)}
                          disabled={!k.is_active}
                          className={`text-xs px-2 py-1 rounded-full border-0 font-medium cursor-pointer ${STATUS_COLORS[k.status]}`}
                        >
                          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                      </td>
                      <td className="py-2 px-2 text-gray-500 text-xs">{k.notes || '—'}</td>
                      <td className="py-2 px-2 text-right">
                        <div className="flex gap-1 justify-end">
                          <button
                            onClick={() => handleMoveUp(k, items)}
                            disabled={idx === 0}
                            className="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                            title="Move up"
                          >↑</button>
                          <button
                            onClick={() => handleMoveDown(k, items)}
                            disabled={idx === items.length - 1}
                            className="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                            title="Move down"
                          >↓</button>
                        </div>
                      </td>
                      <td className="py-2 px-2 text-right">
                        <div className="flex gap-1 justify-end">
                          <button onClick={() => openEdit(k)} className="btn-xs btn-secondary">Edit</button>
                          {k.is_active
                            ? <button onClick={() => handleDisable(k)} className="btn-xs text-red-600 hover:bg-red-50 border border-red-200 rounded px-2 py-0.5 text-xs">Disable</button>
                            : <button onClick={() => handleEnable(k)} className="btn-xs text-green-700 hover:bg-green-50 border border-green-200 rounded px-2 py-0.5 text-xs">Enable</button>
                          }
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ))}

      {/* Add / Edit Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title={editing ? `Edit Kennel — ${editing.code}` : 'Add Kennel'}>
        <form onSubmit={handleSave} className="space-y-4">
          {error && <div className="alert-error">{error}</div>}

          {!editing && (
            <div className="form-group">
              <label className="form-label">Branch *</label>
              <select className="form-input" value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })} required>
                <option value="">Select branch…</option>
                {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
              </select>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div className="form-group">
              <label className="form-label">Code * <span className="text-gray-400 font-normal text-xs">(unique per branch)</span></label>
              <input
                className="form-input font-mono"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                placeholder="e.g. S1"
                required
              />
            </div>
            <div className="form-group">
              <label className="form-label">Name *</label>
              <input
                className="form-input"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="e.g. Front Garden"
                required
              />
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Status</label>
            <select className="form-input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>

          <div className="form-group">
            <label className="form-label">Notes</label>
            <textarea
              className="form-input"
              rows={2}
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
              placeholder="Optional notes…"
            />
          </div>

          <div className="flex gap-3 pt-1">
            <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : (editing ? 'Save Changes' : 'Add Kennel')}</button>
            <button type="button" onClick={() => setShowModal(false)} className="btn-secondary">Cancel</button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
