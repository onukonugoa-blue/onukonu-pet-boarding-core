import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { petsApi } from '../../api/pets'
import type { PetDetail } from '../../api/pets'
import { fmt } from '../../api/client'
import PetDocuments from './PetDocuments'

export default function PetProfile() {
  const { id } = useParams<{ id: string }>()
  const [pet, setPet] = useState<PetDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'info' | 'health' | 'docs'>('info')

  useEffect(() => {
    petsApi.get(Number(id))
      .then(setPet)
      .catch(console.error)
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!pet) return <div className="alert-error">Pet not found</div>

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{pet.name}</h1>
          <p className="text-sm text-gray-500">{pet.pet_type} · {pet.breed ?? 'Unknown breed'} · <Link to={`/clients/${pet.client_id}`} className="text-blue-600 hover:underline">{pet.client_name}</Link></p>
        </div>
        <div className="flex gap-2">
          <Link to={`/pets/${id}/edit`} className="btn-secondary btn-sm">Edit</Link>
          <Link to={`/bookings/new?pet_id=${id}&client_id=${pet.client_id}`} className="btn-primary btn-sm">+ Booking</Link>
        </div>
      </div>

      <div className="tabs-bar">
        {(['info','health','docs'] as const).map((t) => (
          <button key={t} onClick={() => setTab(t)} className={`tab ${tab === t ? 'tab-active' : 'tab-inactive'}`}>
            {t === 'info' ? 'Info' : t === 'health' ? 'Health' : 'Documents'}
          </button>
        ))}
      </div>

      {tab === 'info' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="card">
            <h3 className="font-semibold border-b pb-2 mb-3">Basic Info</h3>
            {[
              ['Type', pet.pet_type],
              ['Breed', pet.breed ?? '—'],
              ['Breed Size', pet.breed_size ?? '—'],
              ['Gender', pet.gender ?? '—'],
              ['Coat', pet.coat ?? '—'],
              ['Weight', pet.weight_kg ? `${pet.weight_kg} kg` : '—'],
              ['Birthday', fmt.date(pet.birthday ?? null)],
              ['Neutered/Spayed', pet.neutered_or_spayed ? 'Yes' : 'No'],
              ['Microchip', pet.microchip_number ?? '—'],
            ].map(([k,v]) => (
              <div key={k} className="flex justify-between text-sm py-1 border-b border-gray-50">
                <span className="text-gray-500">{k}</span><span>{v}</span>
              </div>
            ))}
          </div>
          <div className="card">
            <h3 className="font-semibold border-b pb-2 mb-3">Care Preferences</h3>
            {[
              ['Dietary Preference', pet.dietary_preference ?? '—'],
              ['Additional Meals', pet.additional_meals ?? '—'],
              ['Allergies / Preferences', pet.preferences_or_allergies ?? '—'],
              ['Walk #1', pet.first_walk_schedule ?? '—'],
              ['Walk #2', pet.second_walk_schedule ?? '—'],
              ['Walk #3', pet.third_walk_schedule ?? '—'],
              ['Medication', pet.ongoing_medication ? `Yes — ${pet.medication_detail ?? ''}` : 'None'],
            ].map(([k,v]) => (
              <div key={k} className="flex justify-between text-sm py-1 border-b border-gray-50">
                <span className="text-gray-500">{k}</span><span className="text-right max-w-[60%]">{v}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {tab === 'health' && (
        <div className="card">
          <h3 className="font-semibold border-b pb-2 mb-3">Vaccination & Health</h3>
          {[
            ['Anti-Rabies', fmt.date(pet.anti_rabies_date ?? null)],
            ['DHPPIL', fmt.date(pet.dhppil_date ?? null)],
            ['Corona', fmt.date(pet.corona_date ?? null)],
            ['Kennel Cough', fmt.date(pet.kennel_cough_date ?? null)],
            ['Tick Prevention', pet.tick_prevention ? `Yes — ${pet.tick_prevention_method ?? ''}` : 'No'],
            ['Last Tick Prevention', fmt.date(pet.last_tick_prevention_date ?? null)],
            ['Deworming', fmt.date(pet.deworming_date ?? null)],
            ['Vet Name', pet.vet_name ?? '—'],
            ['Vet Contact', pet.vet_contact ?? '—'],
            ['Major Illness History', pet.major_illness_history ?? '—'],
          ].map(([k,v]) => (
            <div key={k} className="flex justify-between text-sm py-1.5 border-b border-gray-50">
              <span className="text-gray-500">{k}</span><span className="text-right max-w-[60%]">{v}</span>
            </div>
          ))}
        </div>
      )}

      {tab === 'docs' && <PetDocuments petId={Number(id)} />}
    </div>
  )
}
