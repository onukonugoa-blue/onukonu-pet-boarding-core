import { useEffect, useState } from 'react'
import { settingsApi } from '../../api/settings'
import type { BoardingService } from '../../api/settings'
import { useBranchStore } from '../../store/branch'
import { fmt } from '../../api/client'
import Modal from '../../components/Modal'

const ROW_TYPES = ['FLAGS','DAY_BASE','OVERNIGHT_BASE','BREED_SIZE','MEAL','KENNEL_CATEGORY','LONGEVITY','DISCOUNT']
const PET_TYPES = ['DOG','CAT','ANY']
const BOARDING_TYPES = ['DAY','OVERNIGHT']
const BREED_SIZES = ['Toy','Small','Medium','Large','X-Large','Any']

const EMPTY: Partial<BoardingService> = {
  catalogue_name: 'STANDARD', boarding_type: 'OVERNIGHT', pet_type: 'DOG',
  row_type: 'OVERNIGHT_BASE', amount: 0, is_active: 1, sort_order: 0
}

export default function BoardingCatalogue() {
  const [services, setServices] = useState<BoardingService[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<BoardingService | null>(null)
  const [form, setForm] = useState<Partial<BoardingService>>(EMPTY)
  const [saving, setSaving] = useState(false)
  const [filterCatalogue, setFilterCatalogue] = useState('')
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const branches = useBranchStore((s) => s.branches)

  const load = () => {
    setLoading(true)
    settingsApi.getBoardingServices(activeBranchId || undefined)
      .then(setServices).catch(() => {}).finally(() => setLoading(false))
  }
  useEffect(load, [activeBranchId])

  const openNew = () => {
    setEditing(null)
    setForm({ ...EMPTY, branch_id: activeBranchId || undefined })
    setModal(true)
  }
  const openEdit = (s: BoardingService) => { setEditing(s); setForm({...s}); setModal(true) }

  const set = (k: keyof BoardingService, v: unknown) => setForm((f) => ({...f,[k]:v}))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editing) await settingsApi.updateBoardingService(editing.id, form)
      else await settingsApi.createBoardingService(form)
      setModal(false)
      load()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Deactivate this service row?')) return
    await settingsApi.deleteBoardingService(id)
    load()
  }

  const catalogues = [...new Set(services.map((s) => s.catalogue_name))]
  const filtered = filterCatalogue ? services.filter((s) => s.catalogue_name === filterCatalogue) : services

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Boarding Catalogue</h1>
        <button onClick={openNew} className="btn-primary">+ Add Row</button>
      </div>

      <div className="flex gap-2 mb-4 flex-wrap">
        {['', ...catalogues].map((c) => (
          <button key={c} onClick={() => setFilterCatalogue(c)} className={`btn btn-sm ${filterCatalogue===c?'btn-primary':'btn-secondary'}`}>{c||'All'}</button>
        ))}
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr><th>Catalogue</th><th>Type</th><th>Row Type</th><th>Pet Type</th><th>Breed Size</th><th>Amount</th><th>Active</th><th></th></tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={8} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={8} className="text-center py-8 text-gray-400">No rows found</td></tr>
              ) : filtered.map((s) => (
                <tr key={s.id}>
                  <td className="font-medium">{s.catalogue_name}</td>
                  <td><span className="badge-blue">{s.boarding_type}</span></td>
                  <td><span className="badge-gray">{s.row_type}</span></td>
                  <td>{s.pet_type}</td>
                  <td>{s.breed_size ?? '—'}</td>
                  <td>{s.amount != null ? fmt.inr(s.amount) : '—'}</td>
                  <td>{s.is_active ? '✓' : '✗'}</td>
                  <td className="flex gap-1">
                    <button onClick={() => openEdit(s)} className="text-blue-600 text-xs hover:underline">Edit</button>
                    <button onClick={() => handleDelete(s.id)} className="text-red-500 text-xs hover:underline ml-1">Del</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title={editing ? 'Edit Row' : 'New Boarding Row'} width="max-w-xl"
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="bs-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="bs-form" onSubmit={handleSave} className="grid grid-cols-2 gap-3">
          <div className="form-group"><label className="form-label">Branch</label>
            <select className="form-select" value={form.branch_id??0} onChange={(e) => set('branch_id',Number(e.target.value))}>
              <option value={0}>Select…</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div className="form-group"><label className="form-label">Catalogue Name *</label><input className="form-input" required value={form.catalogue_name??''} onChange={(e) => set('catalogue_name',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Boarding Type</label>
            <select className="form-select" value={form.boarding_type??'OVERNIGHT'} onChange={(e) => set('boarding_type',e.target.value)}>{BOARDING_TYPES.map((t) => <option key={t}>{t}</option>)}</select>
          </div>
          <div className="form-group"><label className="form-label">Pet Type</label>
            <select className="form-select" value={form.pet_type??'DOG'} onChange={(e) => set('pet_type',e.target.value)}>{PET_TYPES.map((t) => <option key={t}>{t}</option>)}</select>
          </div>
          <div className="form-group"><label className="form-label">Row Type *</label>
            <select className="form-select" required value={form.row_type??''} onChange={(e) => set('row_type',e.target.value)}><option value="">Select…</option>{ROW_TYPES.map((r) => <option key={r}>{r}</option>)}</select>
          </div>
          <div className="form-group"><label className="form-label">Amount</label><input className="form-input" type="number" step="0.01" value={form.amount??''} onChange={(e) => set('amount',Number(e.target.value))} /></div>
          <div className="form-group"><label className="form-label">Breed Size</label>
            <select className="form-select" value={form.breed_size??''} onChange={(e) => set('breed_size',e.target.value)}><option value="">Any</option>{BREED_SIZES.map((s) => <option key={s}>{s}</option>)}</select>
          </div>
          <div className="form-group"><label className="form-label">Kennel Category</label><input className="form-input" value={form.kennel_category??''} onChange={(e) => set('kennel_category',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Meal Name</label><input className="form-input" value={form.meal_name??''} onChange={(e) => set('meal_name',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Meal Type</label><input className="form-input" value={form.meal_type??''} onChange={(e) => set('meal_type',e.target.value)} /></div>
          <div className="form-group"><label className="form-label">Days</label><input className="form-input" type="number" value={form.days??''} onChange={(e) => set('days',Number(e.target.value))} /></div>
          <div className="form-group"><label className="form-label">Min Pets</label><input className="form-input" type="number" value={form.min_pets??''} onChange={(e) => set('min_pets',Number(e.target.value))} /></div>
          <div className="form-group"><label className="form-label">Sort Order</label><input className="form-input" type="number" value={form.sort_order??0} onChange={(e) => set('sort_order',Number(e.target.value))} /></div>
          <div className="form-group col-span-2"><label className="form-label">Extra Info</label><input className="form-input" value={form.extra_info??''} onChange={(e) => set('extra_info',e.target.value)} /></div>
          <div className="form-group col-span-2 flex items-center gap-2">
            <input id="bs-active" type="checkbox" checked={!!form.is_active} onChange={(e) => set('is_active',e.target.checked?1:0)} /><label htmlFor="bs-active" className="text-sm">Active</label>
          </div>
        </form>
      </Modal>
    </div>
  )
}
