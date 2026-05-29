import { useEffect, useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { clientsApi } from '../../api/clients'
import type { Client, Pet } from '../../api/clients'
import { fmt } from '../../api/client'
import WhatsAppButton from '../../components/WhatsAppButton'
import { useWhatsApp } from '../../hooks/useWhatsApp'

export default function ClientProfile() {
  const { id } = useParams<{ id: string }>()
  const [client, setClient] = useState<Client | null>(null)
  const [pets, setPets] = useState<Pet[]>([])
  const [bookings, setBookings] = useState<any[]>([])
  const [tab, setTab] = useState<'info' | 'pets' | 'bookings'>('info')
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()
  const { onboardingMessage, open } = useWhatsApp()

  useEffect(() => {
    const cid = Number(id)
    setLoading(true)
    Promise.all([
      clientsApi.get(cid),
      clientsApi.pets(cid),
      clientsApi.bookings(cid),
    ]).then(([c, p, b]) => {
      setClient(c)
      setPets(p)
      setBookings(b as any[])
    }).catch(console.error)
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading…</div>
  if (!client) return <div className="alert-error">Client not found.</div>

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="page-title">{client.name}</h1>
          <p className="text-sm text-gray-500">{client.phone} · {client.branch_code ?? 'Branch unknown'}</p>
        </div>
        <div className="flex gap-2">
          <WhatsAppButton
            phone={client.phone}
            message={onboardingMessage(client, pets)}
            label="WhatsApp"
            size="sm"
          />
          <Link to={`/clients/${id}/edit`} className="btn-secondary btn-sm">Edit</Link>
          <Link to={`/bookings/new?client_id=${id}`} className="btn-primary btn-sm">+ Booking</Link>
        </div>
      </div>

      <div className="tabs-bar">
        {(['info','pets','bookings'] as const).map((t) => (
          <button key={t} onClick={() => setTab(t)} className={`tab ${tab === t ? 'tab-active' : 'tab-inactive'}`}>
            {t === 'info' ? 'Info' : t === 'pets' ? `Pets (${pets.length})` : `Bookings (${bookings.length})`}
          </button>
        ))}
      </div>

      {tab === 'info' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="card space-y-3">
            <h3 className="font-semibold text-gray-800 border-b pb-2">Contact Details</h3>
            {[
              ['Phone', client.phone],
              ['Email', client.email ?? '—'],
              ['Address', client.address ?? '—'],
              ['Onboarding', fmt.date(client.onboarding_date ?? null)],
              ['Status', client.status],
              ['T&C Accepted', client.tc_accepted ? 'Yes' : 'No'],
            ].map(([k,v]) => (
              <div key={k} className="flex justify-between text-sm">
                <span className="text-gray-500">{k}</span>
                <span className="font-medium text-right max-w-[60%]">{v}</span>
              </div>
            ))}
          </div>
          <div className="card space-y-3">
            <h3 className="font-semibold text-gray-800 border-b pb-2">Financial</h3>
            {[
              ['Wallet Balance', fmt.inr(client.wallet_balance)],
              ['Outstanding', fmt.inr(client.outstanding_balance)],
            ].map(([k,v]) => (
              <div key={k} className="flex justify-between text-sm">
                <span className="text-gray-500">{k}</span>
                <span className="font-medium">{v}</span>
              </div>
            ))}
            {client.local_guardian_name && (
              <>
                <h3 className="font-semibold text-gray-800 border-b pb-2 pt-2">Local Guardian</h3>
                <div className="flex justify-between text-sm"><span className="text-gray-500">Name</span><span>{client.local_guardian_name}</span></div>
                <div className="flex justify-between text-sm"><span className="text-gray-500">Contact</span><span>{client.local_guardian_contact}</span></div>
              </>
            )}
          </div>
        </div>
      )}

      {tab === 'pets' && (
        <div>
          <div className="flex justify-end mb-3">
            <Link to={`/clients/${id}/pets/new`} className="btn-primary btn-sm">+ Add Pet</Link>
          </div>
          {pets.length === 0 ? (
            <div className="empty-state"><span>🐾</span><p>No pets registered</p></div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              {pets.map((p) => (
                <div key={p.id} className="card hover:shadow-md transition-shadow cursor-pointer" onClick={() => navigate(`/pets/${p.id}`)}>
                  <div className="font-semibold text-blue-600">{p.name}</div>
                  <div className="text-sm text-gray-500">{p.pet_type} · {p.breed ?? 'Unknown breed'}</div>
                  <div className="text-xs text-gray-400 mt-1">{p.gender ?? ''} · {p.breed_size ?? ''}</div>
                  {!p.is_active && <span className="badge-gray mt-1">Archived</span>}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {tab === 'bookings' && (
        <div className="card">
          <div className="table-container">
            <table className="data-table">
              <thead>
                <tr><th>ID</th><th>Date</th><th>Pets</th><th>Status</th><th>Total</th><th></th></tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {bookings.length === 0 ? (
                  <tr><td colSpan={6} className="text-center py-6 text-gray-400">No bookings</td></tr>
                ) : bookings.map((b: any) => (
                  <tr key={b.id}>
                    <td>#{b.id}</td>
                    <td>{fmt.date(b.booking_date)}</td>
                    <td>{b.pet_names ?? '—'}</td>
                    <td><span className="badge-blue">{b.payment_status}</span></td>
                    <td>{fmt.inr(b.total_billing_amount)}</td>
                    <td><Link to={`/bookings/${b.id}`} className="text-blue-600 hover:underline text-xs">View</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
