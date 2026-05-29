import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { clientsApi } from '../../api/clients'
import type { Client } from '../../api/clients'
import { useBranchStore } from '../../store/branch'

const INITIAL: Partial<Client> = {
  name: '', phone: '', email: '', address: '',
  local_guardian_name: '', local_guardian_contact: '',
  status: 'active', tc_accepted: 0,
}

export default function ClientForm() {
  const { id } = useParams<{ id: string }>()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const branches = useBranchStore((s) => s.branches)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const [form, setForm] = useState<Partial<Client>>({ ...INITIAL, home_branch_id: activeBranchId || 0 })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (isEdit) {
      clientsApi.get(Number(id)).then(setForm).catch((e) => setError(e.message))
    }
  }, [id])

  const set = (k: keyof Client, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    if (!form.name?.trim() || !form.phone?.trim()) { setError('Name and phone are required'); return }
    setSaving(true)
    try {
      const saved = isEdit
        ? await clientsApi.update(Number(id), form)
        : await clientsApi.create(form)
      navigate(`/clients/${saved.id}`)
    } catch (e: any) {
      setError(e.message ?? 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <h1 className="page-title mb-5">{isEdit ? 'Edit Client' : 'New Client'}</h1>
      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 max-w-3xl">
        <div className="form-group">
          <label className="form-label">Name *</label>
          <input className="form-input" value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} required />
        </div>
        <div className="form-group">
          <label className="form-label">Phone *</label>
          <input className="form-input" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} required />
        </div>
        <div className="form-group">
          <label className="form-label">Email</label>
          <input className="form-input" type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Home Branch</label>
          <select className="form-select" value={form.home_branch_id ?? 0} onChange={(e) => set('home_branch_id', Number(e.target.value))}>
            <option value={0}>Select branch</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </div>
        <div className="form-group md:col-span-2">
          <label className="form-label">Address</label>
          <textarea className="form-input" rows={2} value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Local Guardian Name</label>
          <input className="form-input" value={form.local_guardian_name ?? ''} onChange={(e) => set('local_guardian_name', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Local Guardian Contact</label>
          <input className="form-input" value={form.local_guardian_contact ?? ''} onChange={(e) => set('local_guardian_contact', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Onboarding Date</label>
          <input className="form-input" type="date" value={form.onboarding_date ?? ''} onChange={(e) => set('onboarding_date', e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Status</label>
          <select className="form-select" value={form.status ?? 'active'} onChange={(e) => set('status', e.target.value)}>
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div className="form-group md:col-span-2 flex items-center gap-2 mt-1">
          <input id="tc" type="checkbox" checked={!!form.tc_accepted} onChange={(e) => set('tc_accepted', e.target.checked ? 1 : 0)} />
          <label htmlFor="tc" className="text-sm text-gray-700">Terms & Conditions accepted</label>
        </div>
        <div className="md:col-span-2 flex gap-3 mt-4">
          <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : 'Save Client'}</button>
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  )
}
