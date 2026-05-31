import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { bookingsApi } from '../../api/bookings'
import { clientsApi } from '../../api/clients'
import { settingsApi } from '../../api/settings'
import type { Client, Pet } from '../../api/clients'
import type { BoardingService } from '../../api/settings'
import { useBranchStore } from '../../store/branch'

interface StayForm {
  pet_id: number
  boarding_service_id: number
  boarding_type: 'DAY' | 'OVERNIGHT'
  check_in_date: string
  check_out_date: string
  check_in_slot: string
  check_out_slot: string
  meal_type: string
  notes: string
}

const SLOTS = ['Morning (7-10)', 'Afternoon (12-3)', 'Evening (5-8)', 'Flexible']

const MEAL_OPTIONS = [
  { label: 'Parent Supplied Meal', value: 'PARENT_SUPPLIED_MEAL' },
  { label: 'Boarding Meals',       value: 'BOARDING_MEALS' },
]

const DEFAULT_STAY: StayForm = {
  pet_id: 0,
  boarding_service_id: 0,
  boarding_type: 'OVERNIGHT',
  check_in_date: '',
  check_out_date: '',
  check_in_slot: 'Morning (7-10)',
  check_out_slot: 'Morning (7-10)',
  meal_type: 'PARENT_SUPPLIED_MEAL',
  notes: '',
}

export default function BookingCreate() {
  const navigate = useNavigate()
  const [sp] = useSearchParams()
  const initClientId = Number(sp.get('client_id') ?? 0)
  const initPetId    = Number(sp.get('pet_id') ?? 0)
  const branches = useBranchStore((s) => s.branches)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)

  const [clientSearch, setClientSearch] = useState('')
  const [clientResults, setClientResults] = useState<Client[]>([])
  const [selectedClient, setSelectedClient] = useState<Client | null>(null)
  const [pets, setPets] = useState<Pet[]>([])
  const [boardingServices, setBoardingServices] = useState<BoardingService[]>([])
  const [branchId, setBranchId] = useState(activeBranchId || 0)
  const [stays, setStays] = useState<StayForm[]>([{ ...DEFAULT_STAY, pet_id: initPetId }])
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (initClientId) {
      clientsApi.get(initClientId).then((c) => {
        setSelectedClient(c)
        setBranchId(c.home_branch_id)
        return clientsApi.pets(initClientId)
      }).then(setPets).catch(console.error)
    }
  }, [initClientId])

  useEffect(() => {
    if (branchId) {
      settingsApi.getBoardingServices(branchId)
        .then((rows) => setBoardingServices(rows.filter((r) => r.is_active)))
        .catch(console.error)
    } else {
      setBoardingServices([])
    }
  }, [branchId])

  const getCatalogueOptions = (boarding_type: 'DAY' | 'OVERNIGHT') => {
    const seen = new Set<string>()
    const opts: { label: string; id: number }[] = []
    for (const s of boardingServices) {
      if (s.boarding_type === boarding_type && !seen.has(s.catalogue_name)) {
        seen.add(s.catalogue_name)
        opts.push({ label: s.catalogue_name, id: s.id })
      }
    }
    return opts
  }

  const searchClients = async () => {
    if (!clientSearch.trim()) return
    const r = await clientsApi.list({ search: clientSearch, per_page: 10 })
    setClientResults(r.data)
  }

  const selectClient = async (c: Client) => {
    setSelectedClient(c)
    setClientResults([])
    setClientSearch('')
    setBranchId(c.home_branch_id)
    const pList = await clientsApi.pets(c.id)
    setPets(pList)
  }

  const updateStay = (i: number, k: keyof StayForm, v: string | number) =>
    setStays((prev) => prev.map((s, idx) => {
      if (idx !== i) return s
      const updated = { ...s, [k]: v }
      if (k === 'boarding_type') updated.boarding_service_id = 0
      return updated
    }))

  const addStay = () => setStays((prev) => [...prev, {
    ...DEFAULT_STAY,
    check_in_date:  stays[0]?.check_in_date ?? '',
    check_out_date: stays[0]?.check_out_date ?? '',
  }])

  const removeStay = (i: number) => setStays((prev) => prev.filter((_, idx) => idx !== i))

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    if (!selectedClient) { setError('Please select a client'); return }
    if (!branchId) { setError('Please select a branch'); return }
    if (stays.some((s) => !s.pet_id)) { setError('All stays must have a pet selected'); return }
    if (stays.some((s) => !s.boarding_service_id)) { setError('All stays must have a boarding service selected'); return }
    setSaving(true)
    try {
      const bk = await bookingsApi.create({ client_id: selectedClient.id, branch_id: branchId, notes, stays })
      navigate(`/bookings/${bk.id}`)
    } catch (e: any) {
      setError(e.message ?? 'Failed to create booking')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <h1 className="page-title mb-5">New Booking</h1>
      {error && <div className="alert-error">{error}</div>}

      <form onSubmit={handleSubmit} className="space-y-5 max-w-3xl">
        {/* Client selection */}
        <div className="card">
          <h2 className="font-semibold mb-3 border-b pb-2">Client</h2>
          {selectedClient ? (
            <div className="flex items-center justify-between">
              <div>
                <div className="font-medium">{selectedClient.name}</div>
                <div className="text-sm text-gray-500">{selectedClient.phone}</div>
              </div>
              <button type="button" onClick={() => { setSelectedClient(null); setPets([]) }} className="btn-secondary btn-sm">Change</button>
            </div>
          ) : (
            <div>
              <div className="flex gap-2 mb-2">
                <input
                  className="form-input flex-1"
                  placeholder="Search client by name or phone…"
                  value={clientSearch}
                  onChange={(e) => setClientSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), searchClients())}
                />
                <button type="button" onClick={searchClients} className="btn-secondary">Search</button>
              </div>
              {clientResults.length > 0 && (
                <div className="border rounded-md divide-y max-h-48 overflow-y-auto">
                  {clientResults.map((c) => (
                    <div key={c.id} className="p-2 hover:bg-gray-50 cursor-pointer text-sm" onClick={() => selectClient(c)}>
                      <span className="font-medium">{c.name}</span> <span className="text-gray-500">· {c.phone}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Branch */}
        <div className="form-group max-w-xs">
          <label className="form-label">Branch</label>
          <select className="form-select" value={branchId} onChange={(e) => setBranchId(Number(e.target.value))}>
            <option value={0}>Select branch…</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </div>

        {/* Stays */}
        <div className="card">
          <div className="flex items-center justify-between mb-3 border-b pb-2">
            <h2 className="font-semibold">Pet Stays</h2>
            <button type="button" onClick={addStay} className="btn-secondary btn-sm">+ Add Pet</button>
          </div>
          {stays.map((s, i) => {
            const catalogueOptions = getCatalogueOptions(s.boarding_type)
            return (
              <div key={i} className="border rounded-lg p-3 mb-3 bg-gray-50">
                <div className="flex justify-between items-center mb-2">
                  <span className="text-sm font-medium text-gray-700">Stay #{i + 1}</span>
                  {stays.length > 1 && <button type="button" onClick={() => removeStay(i)} className="text-red-500 text-xs">Remove</button>}
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="form-group">
                    <label className="form-label">Pet</label>
                    <select className="form-select" value={s.pet_id} onChange={(e) => updateStay(i, 'pet_id', Number(e.target.value))}>
                      <option value={0}>Select pet…</option>
                      {pets.filter((p) => p.is_active).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.breed})</option>)}
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label">Boarding Type</label>
                    <select className="form-select" value={s.boarding_type} onChange={(e) => updateStay(i, 'boarding_type', e.target.value)}>
                      <option value="OVERNIGHT">Overnight</option>
                      <option value="DAY">Day</option>
                    </select>
                  </div>
                  <div className="form-group col-span-2">
                    <label className="form-label">Boarding Service *</label>
                    <select
                      className="form-select"
                      value={s.boarding_service_id}
                      onChange={(e) => updateStay(i, 'boarding_service_id', Number(e.target.value))}
                    >
                      <option value={0}>
                        {!branchId
                          ? 'Select a branch first'
                          : catalogueOptions.length === 0
                            ? 'No services configured for this branch / type'
                            : 'Select boarding service…'}
                      </option>
                      {catalogueOptions.map((opt) => (
                        <option key={opt.id} value={opt.id}>{opt.label}</option>
                      ))}
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label">Check-in Date</label>
                    <input className="form-input" type="date" value={s.check_in_date} onChange={(e) => updateStay(i, 'check_in_date', e.target.value)} required />
                  </div>
                  <div className="form-group">
                    <label className="form-label">Check-out Date</label>
                    <input className="form-input" type="date" value={s.check_out_date} onChange={(e) => updateStay(i, 'check_out_date', e.target.value)} required />
                  </div>
                  <div className="form-group">
                    <label className="form-label">Check-in Slot</label>
                    <select className="form-select" value={s.check_in_slot} onChange={(e) => updateStay(i, 'check_in_slot', e.target.value)}>
                      {SLOTS.map((sl) => <option key={sl}>{sl}</option>)}
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label">Check-out Slot</label>
                    <select className="form-select" value={s.check_out_slot} onChange={(e) => updateStay(i, 'check_out_slot', e.target.value)}>
                      {SLOTS.map((sl) => <option key={sl}>{sl}</option>)}
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label">Meal Type</label>
                    <select className="form-select" value={s.meal_type} onChange={(e) => updateStay(i, 'meal_type', e.target.value)}>
                      {MEAL_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                    </select>
                  </div>
                </div>
              </div>
            )
          })}
        </div>

        {/* Notes */}
        <div className="form-group">
          <label className="form-label">Booking Notes</label>
          <textarea className="form-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        <div className="flex gap-3">
          <button type="submit" disabled={saving} className="btn-primary">{saving ? 'Creating…' : 'Create Booking'}</button>
          <button type="button" onClick={() => navigate(-1)} className="btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  )
}
