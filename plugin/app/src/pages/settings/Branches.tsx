import { useEffect, useState } from 'react'
import { branchesApi } from '../../api/branches'
import { useBranchStore } from '../../store/branch'
import type { Branch } from '../../store/branch'
import Modal from '../../components/Modal'

export default function Branches() {
  const { branches, setBranches } = useBranchStore()
  const [loading, setLoading] = useState(branches.length === 0)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Branch | null>(null)
  const [form, setForm] = useState<Partial<Branch>>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (branches.length === 0) {
      setLoading(true)
      branchesApi.list().then(setBranches).catch(() => {}).finally(() => setLoading(false))
    }
  }, [])

  const openEdit = (b: Branch) => {
    setEditing(b)
    setForm({ ...b })
    setModal(true)
  }

  const set = (k: keyof Branch, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!editing) return
    setSaving(true)
    try {
      const updated = await branchesApi.update(editing.id, form)
      setBranches(branches.map((b) => b.id === editing.id ? updated : b))
      setModal(false)
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Branches</h1>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16 text-gray-400">Loading…</div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {branches.map((b) => (
            <div key={b.id} className="card">
              <div className="flex items-start justify-between mb-2">
                <div>
                  <div className="font-semibold text-gray-900">{b.name}</div>
                  <div className="text-xs text-gray-500">{b.code}</div>
                </div>
                <button onClick={() => openEdit(b)} className="btn-secondary btn-sm">Edit</button>
              </div>
              {[
                ['Location', b.location],
                ['Phone', b.phone ?? '—'],
                ['Email', b.email ?? '—'],
                ['Address', b.address ?? '—'],
              ].map(([k, v]) => (
                <div key={k} className="flex justify-between text-xs py-0.5">
                  <span className="text-gray-400">{k}</span>
                  <span className="text-gray-700 text-right max-w-[60%]">{v}</span>
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      <Modal open={modal} onClose={() => setModal(false)} title={`Edit Branch — ${editing?.name}`}
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="branch-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="branch-form" onSubmit={handleSave} className="space-y-3">
          <div className="form-group"><label className="form-label">Name *</label><input className="form-input" required value={form.name??''} onChange={(e) => set('name',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Code</label><input className="form-input" value={form.code??''} onChange={(e) => set('code',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Location</label><input className="form-input" value={form.location??''} onChange={(e) => set('location',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Phone</label><input className="form-input" value={form.phone??''} onChange={(e) => set('phone',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Email</label><input className="form-input" type="email" value={form.email??''} onChange={(e) => set('email',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Address</label><textarea className="form-input" rows={2} value={form.address??''} onChange={(e) => set('address',e.target.value)} /></div>
        </form>
      </Modal>
    </div>
  )
}
