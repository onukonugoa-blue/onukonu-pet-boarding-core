import { useEffect, useState } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { petsApi } from '../../api/pets'
import type { PetDetail } from '../../api/pets'
import { clientsApi } from '../../api/clients'

const INITIAL: Partial<PetDetail> = {
  name: '', pet_type: 'Dog', breed: '', breed_size: '', gender: 'Unknown',
  coat: '', dietary_preference: 'Vegetarian',
}

export default function PetForm() {
  const { id, clientId: routeClientId } = useParams<{ id?: string; clientId?: string }>()
  const [sp] = useSearchParams()
  // clientId comes from the route param on /clients/:clientId/pets/new,
  // or from ?client_id= as a fallback (e.g. direct links).
  const clientId = routeClientId ? Number(routeClientId) : Number(sp.get('client_id') ?? 0)
  // isEdit is true only when the pet :id param is present — i.e. /pets/:id/edit.
  // On /clients/:clientId/pets/new the :id param is absent, so isEdit is correctly false.
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [form, setForm] = useState<Partial<PetDetail>>(INITIAL)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (isEdit) petsApi.get(Number(id)).then(setForm).catch((e) => setError(e.message))
  }, [id])

  const set = (k: keyof PetDetail, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    if (!form.name?.trim()) { setError('Pet name is required'); return }
    setSaving(true)
    try {
      if (isEdit) {
        const saved = await petsApi.update(Number(id), form)
        navigate(`/pets/${saved.id}`)
      } else {
        const saved = await clientsApi.createPet(clientId, form)
        navigate(`/pets/${saved.id}`)
      }
    } catch (e: any) {
      setError(e.message ?? 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <h1 className="page-title mb-5">{isEdit ? 'Edit Pet' : 'New Pet'}</h1>
      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 max-w-3xl">
        <div className="form-group"><label className="form-label">Pet Name *</label><input className="form-input" value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} required /></div>
        <div className="form-group"><label className="form-label">Pet Type</label>
          <select className="form-select" value={form.pet_type ?? 'Dog'} onChange={(e) => set('pet_type', e.target.value)}>
            {['Dog','Cat','Other'].map((t) => <option key={t}>{t}</option>)}
          </select>
        </div>
        <div className="form-group"><label className="form-label">Breed</label><input className="form-input" value={form.breed ?? ''} onChange={(e) => set('breed', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Breed Size</label>
          <select className="form-select" value={form.breed_size ?? ''} onChange={(e) => set('breed_size', e.target.value)}>
            <option value="">Select…</option>
            {['Toy','Small','Medium','Large','X-Large'].map((s) => <option key={s}>{s}</option>)}
          </select>
        </div>
        <div className="form-group"><label className="form-label">Gender</label>
          <select className="form-select" value={form.gender ?? 'Unknown'} onChange={(e) => set('gender', e.target.value)}>
            {['Male','Female','Unknown'].map((g) => <option key={g}>{g}</option>)}
          </select>
        </div>
        <div className="form-group"><label className="form-label">Weight (kg)</label><input className="form-input" type="number" step="0.1" value={form.weight_kg ?? ''} onChange={(e) => set('weight_kg', Number(e.target.value))} /></div>
        <div className="form-group"><label className="form-label">Birthday</label><input className="form-input" type="date" value={form.birthday ?? ''} onChange={(e) => set('birthday', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Coat</label><input className="form-input" value={form.coat ?? ''} onChange={(e) => set('coat', e.target.value)} /></div>
        <div className="form-group md:col-span-2"><label className="form-label">Dietary Preference</label>
          <select className="form-select" value={form.dietary_preference ?? ''} onChange={(e) => set('dietary_preference', e.target.value)}>
            <option value="">Select…</option>
            {['Vegetarian','Non-Vegetarian','Home Food','Royal Canin','Other'].map((d) => <option key={d}>{d}</option>)}
          </select>
        </div>
        <div className="form-group md:col-span-2"><label className="form-label">Allergies / Preferences</label><textarea className="form-input" rows={2} value={form.preferences_or_allergies ?? ''} onChange={(e) => set('preferences_or_allergies', e.target.value)} /></div>
        <div className="form-group md:col-span-2"><label className="form-label">Medication Details</label><input className="form-input" value={form.medication_detail ?? ''} onChange={(e) => set('medication_detail', e.target.value)} /></div>
        <div className="form-group md:col-span-2 flex items-center gap-2">
          <input id="neut" type="checkbox" checked={!!form.neutered_or_spayed} onChange={(e) => set('neutered_or_spayed', e.target.checked ? 1 : 0)} />
          <label htmlFor="neut" className="text-sm">Neutered / Spayed</label>
        </div>
        <div className="form-group"><label className="form-label">Vet Name</label><input className="form-input" value={form.vet_name ?? ''} onChange={(e) => set('vet_name', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Vet Contact</label><input className="form-input" value={form.vet_contact ?? ''} onChange={(e) => set('vet_contact', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Anti-Rabies Date</label><input className="form-input" type="date" value={form.anti_rabies_date ?? ''} onChange={(e) => set('anti_rabies_date', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">DHPPIL Date</label><input className="form-input" type="date" value={form.dhppil_date ?? ''} onChange={(e) => set('dhppil_date', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Kennel Cough Date</label><input className="form-input" type="date" value={form.kennel_cough_date ?? ''} onChange={(e) => set('kennel_cough_date', e.target.value)} /></div>
        <div className="form-group"><label className="form-label">Deworming Date</label><input className="form-input" type="date" value={form.deworming_date ?? ''} onChange={(e) => set('deworming_date', e.target.value)} /></div>
        <div className="md:col-span-2 flex gap-3 mt-4">
          <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Saving…' : 'Save Pet'}</button>
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  )
}
