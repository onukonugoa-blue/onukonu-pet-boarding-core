import { useEffect, useState } from 'react'
import { settingsApi } from '../../api/settings'
import type { AddonService } from '../../api/settings'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import Modal from '../../components/Modal'

const SERVICE_TYPES: AddonService['service_type'][] = ['FLAT','DISTANCE_SLAB']
const VISIBILITIES: AddonService['visibility'][] = ['PUBLIC','PRIVATE']
const EMPTY: Partial<AddonService> = {
  name: '', service_type: 'FLAT', base_amount: 0, visibility: 'PUBLIC', is_active: 1, sort_order: 0
}

export default function AddonCatalogue() {
  const [addons, setAddons] = useState<AddonService[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<AddonService | null>(null)
  const [form, setForm] = useState<Partial<AddonService>>(EMPTY)
  const [saving, setSaving] = useState(false)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const branches = useBranchStore((s) => s.branches)

  const load = () => {
    setLoading(true)
    settingsApi.getAddonServices(activeBranchId || undefined)
      .then(setAddons).catch(() => {}).finally(() => setLoading(false))
  }
  useEffect(load, [activeBranchId])

  const openNew = () => { setEditing(null); setForm({...EMPTY, branch_id: activeBranchId||undefined}); setModal(true) }
  const openEdit = (a: AddonService) => { setEditing(a); setForm({...a}); setModal(true) }
  const set = (k: keyof AddonService, v: unknown) => setForm((f) => ({...f,[k]:v}))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editing) await settingsApi.updateAddonService(editing.id, form)
      else await settingsApi.createAddonService(form)
      setModal(false)
      load()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Deactivate this add-on?')) return
    await settingsApi.deleteAddonService(id)
    load()
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Add-on Services</h1>
        <button onClick={openNew} className="btn-primary">+ Add Service</button>
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead><tr><th>Name</th><th>Type</th><th>Base Amount</th><th>Visibility</th><th>Active</th><th></th></tr></thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={6} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : addons.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-8 text-gray-400">No add-ons found</td></tr>
              ) : addons.map((a) => (
                <tr key={a.id}>
                  <td className="font-medium">{a.name}</td>
                  <td><span className="badge-blue">{a.service_type}</span></td>
                  <td>{fmt.inr(a.base_amount)}</td>
                  <td>{a.visibility}</td>
                  <td>{a.is_active ? '✓' : '✗'}</td>
                  <td className="flex gap-1">
                    <button onClick={() => openEdit(a)} className="text-blue-600 text-xs hover:underline">Edit</button>
                    <button onClick={() => handleDelete(a.id)} className="text-red-500 text-xs hover:underline ml-1">Del</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title={editing ? 'Edit Add-on' : 'New Add-on Service'} width="max-w-lg"
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="addon-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="addon-form" onSubmit={handleSave} className="grid grid-cols-2 gap-3">
          <div className="form-group col-span-2"><label className="form-label">Name *</label><input className="form-input" required value={form.name??''} onChange={(e) => set('name',e.target.value)} /></div>
          <div className="form-group col-span-2"><label className="form-label">Description</label><input className="form-input" value={form.description??''} onChange={(e) => set('description',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Branch</label>
            <select className="form-select" value={form.branch_id??0} onChange={(e) => set('branch_id',Number(e.target.value))}>
              <option value={0}>Select…</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div className="form-group"><label className="form-label">Service Type</label>
            <select className="form-select" value={form.service_type??'FLAT'} onChange={(e) => set('service_type',e.target.value)}>{SERVICE_TYPES.map((t) => <option key={t}>{t}</option>)}</select>
          </div>
          <div className="form-group"><label className="form-label">Base Amount</label><input className="form-input" type="number" step="0.01" value={form.base_amount??''} onChange={(e) => set('base_amount',Number(e.target.value))} /></div>
          <div className="form-group"><label className="form-label">Visibility</label>
            <select className="form-select" value={form.visibility??'PUBLIC'} onChange={(e) => set('visibility',e.target.value)}>{VISIBILITIES.map((v) => <option key={v}>{v}</option>)}</select>
          </div>
          {form.service_type === 'DISTANCE_SLAB' && (
            <>
              <div className="form-group"><label className="form-label">Distance Up To (km)</label><input className="form-input" type="number" step="0.1" value={form.distance_up_to??''} onChange={(e) => set('distance_up_to',Number(e.target.value))} /></div>
              <div className="form-group"><label className="form-label">Slab Amount</label><input className="form-input" type="number" step="0.01" value={form.distance_slab_amount??''} onChange={(e) => set('distance_slab_amount',Number(e.target.value))} /></div>
            </>
          )}
          <div className="form-group"><label className="form-label">Sort Order</label><input className="form-input" type="number" value={form.sort_order??0} onChange={(e) => set('sort_order',Number(e.target.value))} /></div>
          <div className="form-group col-span-2 flex items-center gap-2">
            <input id="ao-active" type="checkbox" checked={!!form.is_active} onChange={(e) => set('is_active',e.target.checked?1:0)} /><label htmlFor="ao-active" className="text-sm">Active</label>
          </div>
        </form>
      </Modal>
    </div>
  )
}
