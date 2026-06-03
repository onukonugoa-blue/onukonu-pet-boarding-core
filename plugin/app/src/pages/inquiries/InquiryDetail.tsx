import { useState, useEffect, useRef } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { inquiriesApi, STATUS_LABELS, STATUS_COLORS } from '../../api/inquiries'
import type { InquiryDetail as IDetail, InquiryStatus, ExistingClient, SendOnboardingResult } from '../../api/inquiries'
import { fmt } from '../../api/client'

export default function InquiryDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [data, setData]         = useState<IDetail | null>(null)
  const [loading, setLoading]   = useState(true)
  const [error, setError]       = useState('')

  // UI states
  const [noteText, setNoteText]           = useState('')
  const [addingNote, setAddingNote]       = useState(false)
  const [sendingOnboard, setSendingOnboard] = useState(false)
  const [sendResult, setSendResult]       = useState<SendOnboardingResult | null>(null)
  const [deliveryMethod, setDeliveryMethod] = useState<'EMAIL' | 'WHATSAPP' | 'MANUAL'>('WHATSAPP')
  const [showSendPanel, setShowSendPanel] = useState(false)

  // Convert flow
  const [showConvertModal, setShowConvertModal] = useState(false)
  const [dupCheckResult, setDupCheckResult]     = useState<{ duplicate_found: boolean; client: ExistingClient | null } | null>(null)
  const [dupChecking, setDupChecking]           = useState(false)
  const [converting, setConverting]             = useState(false)
  const [convertBranchId, setConvertBranchId]   = useState<number>(0)
  const [branches, setBranches]                 = useState<{ id: number; name: string }[]>([])
  const [convertError, setConvertError]         = useState('')

  // Reject
  const [showRejectModal, setShowRejectModal] = useState(false)
  const [rejectReason, setRejectReason]       = useState('')
  const [rejecting, setRejecting]             = useState(false)

  const noteRef = useRef<HTMLTextAreaElement>(null)

  useEffect(() => {
    load()
    loadBranches()
  }, [id])

  async function load() {
    setLoading(true); setError('')
    try {
      const res = await inquiriesApi.get(Number(id))
      setData(res)
      setConvertBranchId(res.inquiry.branch_id ?? window.OPB?.user?.branchId ?? 0)
    } catch (e: any) {
      setError(e.message || 'Failed to load inquiry.')
    } finally {
      setLoading(false)
    }
  }

  async function loadBranches() {
    try {
      const res = await fetch(`${window.OPB?.apiBase ?? '/wp-json/opb/v1'}/branches`, {
        headers: { 'X-WP-Nonce': window.OPB?.nonce ?? '' }
      })
      if (res.ok) {
        const json = await res.json()
        setBranches(Array.isArray(json) ? json : json.data ?? [])
      }
    } catch { /* silent */ }
  }

  async function addNote() {
    if (!noteText.trim()) return
    setAddingNote(true)
    try {
      const note = await inquiriesApi.addNote(Number(id), noteText.trim())
      setData(prev => prev ? { ...prev, notes: [...prev.notes, note] } : prev)
      setNoteText('')
    } catch (e: any) {
      alert(e.message || 'Failed to add note.')
    } finally {
      setAddingNote(false)
    }
  }

  async function updateStatus(status: InquiryStatus) {
    try {
      const res = await inquiriesApi.updateStatus(Number(id), status)
      setData(res)
    } catch (e: any) {
      alert(e.message || 'Failed to update status.')
    }
  }

  async function handleSendOnboarding() {
    setSendingOnboard(true)
    try {
      const res = await inquiriesApi.sendOnboarding(Number(id), deliveryMethod)
      setSendResult(res)
      await load()
      setShowSendPanel(false)
      if (deliveryMethod === 'WHATSAPP' && res.whatsapp_url) {
        window.open(res.whatsapp_url, '_blank')
      }
    } catch (e: any) {
      alert(e.message || 'Failed to send onboarding.')
    } finally {
      setSendingOnboard(false)
    }
  }

  async function openConvertModal() {
    setShowConvertModal(true)
    setDupChecking(true)
    setDupCheckResult(null)
    setConvertError('')
    try {
      const res = await inquiriesApi.duplicateCheck(Number(id))
      setDupCheckResult(res)
    } catch (e: any) {
      setConvertError(e.message || 'Duplicate check failed.')
    } finally {
      setDupChecking(false)
    }
  }

  async function handleConvert() {
    if (!convertBranchId) { setConvertError('Please select a branch for this client.'); return }
    setConverting(true); setConvertError('')
    try {
      const res = await inquiriesApi.convert(Number(id), convertBranchId)
      setShowConvertModal(false)
      navigate(`/clients/${res.client_id}`)
    } catch (e: any) {
      setConvertError(e.message || 'Conversion failed.')
      setConverting(false)
    }
  }

  async function handleReject() {
    setRejecting(true)
    try {
      await inquiriesApi.reject(Number(id), rejectReason)
      setShowRejectModal(false)
      await load()
    } catch (e: any) {
      alert(e.message || 'Failed to reject.')
    } finally {
      setRejecting(false)
    }
  }

  async function handleArchive() {
    if (!confirm('Archive this inquiry?')) return
    try {
      await inquiriesApi.archive(Number(id))
      await load()
    } catch (e: any) {
      alert(e.message || 'Failed to archive.')
    }
  }

  if (loading) return <div className="p-8 text-center text-gray-400">Loading…</div>
  if (error)   return <div className="p-8 text-center text-red-600">{error}</div>
  if (!data)   return null

  const { inquiry, notes, onboarding_client, onboarding_pets, documents, existing_client } = data
  const isTerminal = ['CONVERTED', 'REJECTED', 'ARCHIVED'].includes(inquiry.status)
  const canConvert = inquiry.status === 'READY_FOR_REVIEW' || inquiry.status === 'ONBOARDING_COMPLETED'

  return (
    <div className="p-4 max-w-5xl mx-auto space-y-4">
      {/* Header */}
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-3 flex-wrap">
          <button onClick={() => navigate('/inquiries')} className="text-gray-400 hover:text-gray-600 text-sm">← Back</button>
          <h1 className="text-lg font-bold text-blue-900">{inquiry.owner_name}</h1>
          <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${STATUS_COLORS[inquiry.status]}`}>
            {STATUS_LABELS[inquiry.status]}
          </span>
          {inquiry.existing_client_id && (
            <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-100 text-amber-800">⚠ Existing Client Detected</span>
          )}
        </div>
        {!isTerminal && (
          <div className="flex gap-2 flex-wrap">
            {!showSendPanel && (
              <button
                onClick={() => setShowSendPanel(true)}
                className="text-sm bg-purple-700 text-white px-3 py-1.5 rounded-lg hover:bg-purple-600 font-medium"
              >
                📨 Send Onboarding
              </button>
            )}
            {canConvert && (
              <button
                onClick={openConvertModal}
                className="text-sm bg-green-700 text-white px-3 py-1.5 rounded-lg hover:bg-green-600 font-medium"
              >
                ✓ Convert to Client
              </button>
            )}
            <button
              onClick={() => setShowRejectModal(true)}
              className="text-sm bg-red-50 text-red-700 border border-red-200 px-3 py-1.5 rounded-lg hover:bg-red-100"
            >
              Reject
            </button>
            <button
              onClick={handleArchive}
              className="text-sm bg-gray-100 text-gray-600 px-3 py-1.5 rounded-lg hover:bg-gray-200"
            >
              Archive
            </button>
          </div>
        )}
      </div>

      {/* Send Onboarding Panel */}
      {showSendPanel && (
        <div className="bg-purple-50 border border-purple-200 rounded-xl p-4">
          <h3 className="font-semibold text-purple-900 mb-3">Send Onboarding Link</h3>
          <div className="mb-3">
            <div className="text-xs font-medium text-gray-600 mb-1.5">Onboarding URL</div>
            <div className="flex gap-2">
              <input
                readOnly
                value={inquiry.onboarding_url ?? ''}
                className="form-input text-sm flex-1 bg-white text-gray-700 font-mono text-xs"
              />
              <button
                onClick={() => navigator.clipboard.writeText(inquiry.onboarding_url ?? '')}
                className="text-sm bg-white border border-gray-200 px-3 py-1.5 rounded-lg hover:bg-gray-50"
              >
                Copy
              </button>
            </div>
          </div>
          <div className="flex items-center gap-3 flex-wrap">
            <div className="flex gap-2">
              {(['WHATSAPP', 'EMAIL', 'MANUAL'] as const).map(m => (
                <button
                  key={m}
                  onClick={() => setDeliveryMethod(m)}
                  className={`text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors ${
                    deliveryMethod === m
                      ? 'bg-purple-700 text-white border-purple-700'
                      : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'
                  }`}
                >
                  {m === 'WHATSAPP' ? '📱 WhatsApp' : m === 'EMAIL' ? '✉️ Email' : '🔗 Manual'}
                </button>
              ))}
            </div>
            <button
              onClick={handleSendOnboarding}
              disabled={sendingOnboard}
              className="text-sm bg-purple-700 text-white px-4 py-1.5 rounded-lg hover:bg-purple-600 disabled:opacity-50 font-medium"
            >
              {sendingOnboard ? 'Sending…' : deliveryMethod === 'WHATSAPP' ? '📱 Open WhatsApp' : deliveryMethod === 'EMAIL' ? 'Mark as Email Sent' : 'Mark as Sent'}
            </button>
            <button onClick={() => setShowSendPanel(false)} className="text-sm text-gray-400 hover:text-gray-600">
              Cancel
            </button>
          </div>
          {deliveryMethod === 'WHATSAPP' && (
            <p className="text-xs text-purple-700 mt-2">
              WhatsApp will open with a pre-filled message. The status will be updated to Onboarding Sent.
            </p>
          )}
        </div>
      )}

      {sendResult && (
        <div className="bg-green-50 border border-green-200 rounded-xl p-3 text-sm text-green-800">
          ✓ Onboarding link sent via {sendResult.delivery_method} at {fmt.datetime(sendResult.sent_at)}.
          <button onClick={() => setSendResult(null)} className="ml-3 text-green-600 underline text-xs">Dismiss</button>
        </div>
      )}

      {/* Existing Client Warning */}
      {existing_client && (
        <div className="bg-amber-50 border border-amber-300 rounded-xl p-4">
          <div className="flex items-start gap-2 mb-2">
            <span className="text-amber-600 text-lg">⚠</span>
            <div>
              <div className="font-semibold text-amber-900">Existing Client Found</div>
              <div className="text-sm text-amber-800 mt-0.5">
                Matched by <strong>{existing_client.match_type === 'phone' ? 'phone number' : 'email address'}</strong>.
              </div>
            </div>
          </div>
          <div className="bg-white rounded-lg p-3 text-sm border border-amber-200 flex items-center justify-between gap-4 flex-wrap">
            <div>
              <div className="font-semibold text-gray-900">{existing_client.name}</div>
              <div className="text-gray-500 text-xs">{existing_client.phone} · {existing_client.branch_name}</div>
              <div className="text-gray-500 text-xs">{existing_client.pet_count} pet(s) · {existing_client.booking_count} booking(s)</div>
            </div>
            <div className="flex gap-2 flex-wrap">
              <Link
                to={`/clients/${existing_client.id}`}
                className="text-xs bg-blue-800 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700"
              >
                Open Client
              </Link>
              <Link
                to={`/bookings/new?client_id=${existing_client.id}`}
                className="text-xs bg-green-700 text-white px-3 py-1.5 rounded-lg hover:bg-green-600"
              >
                Create Booking
              </Link>
            </div>
          </div>
        </div>
      )}

      <div className="grid lg:grid-cols-3 gap-4">
        {/* Left column */}
        <div className="lg:col-span-2 space-y-4">

          {/* Inquiry Details */}
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h2 className="font-semibold text-gray-900 mb-3">Inquiry Details</h2>
            <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
              <Row label="Name"     value={inquiry.owner_name} />
              <Row label="Phone"    value={inquiry.phone} />
              <Row label="Email"    value={inquiry.email} />
              <Row label="Branch"   value={inquiry.branch_name} />
              <Row label="Pet"      value={inquiry.pet_name && inquiry.pet_type ? `${inquiry.pet_name} (${inquiry.pet_type})` : inquiry.pet_name} />
              <Row label="Check-In" value={fmt.date(inquiry.desired_check_in ?? null)} />
              <Row label="Check-Out" value={fmt.date(inquiry.desired_check_out ?? null)} />
              <Row label="Source"   value={inquiry.source} />
              <Row label="Received" value={fmt.datetime(inquiry.created_at)} />
              {inquiry.onboarding_sent_at && (
                <Row label="Onboarding Sent" value={fmt.datetime(inquiry.onboarding_sent_at) + ' · ' + (inquiry.delivery_method ?? '')} />
              )}
              {inquiry.converted_client_id && (
                <div className="sm:col-span-2">
                  <span className="text-xs font-semibold text-gray-400 uppercase block mb-0.5">Converted Client</span>
                  <Link to={`/clients/${inquiry.converted_client_id}`} className="text-sm text-blue-700 hover:underline">
                    View Client #{inquiry.converted_client_id}
                  </Link>
                </div>
              )}
            </div>
            {inquiry.message && (
              <div className="mt-3 pt-3 border-t border-gray-100">
                <div className="text-xs font-semibold text-gray-400 uppercase mb-1">Message</div>
                <p className="text-sm text-gray-700 whitespace-pre-wrap">{inquiry.message}</p>
              </div>
            )}
            {/* Status update */}
            {!isTerminal && (
              <div className="mt-3 pt-3 border-t border-gray-100 flex items-center gap-2 flex-wrap">
                <span className="text-xs text-gray-500 font-medium">Mark as:</span>
                {(['CONTACTED', 'READY_FOR_REVIEW'] as InquiryStatus[]).map(s => (
                  <button
                    key={s}
                    onClick={() => updateStatus(s)}
                    disabled={inquiry.status === s}
                    className="text-xs px-2.5 py-1 rounded-full border font-medium disabled:opacity-40 disabled:cursor-not-allowed hover:border-gray-500 transition-colors"
                  >
                    {STATUS_LABELS[s]}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Onboarding Client Data */}
          {onboarding_client && (
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <div className="flex items-center justify-between mb-3">
                <h2 className="font-semibold text-gray-900">Onboarding Information</h2>
                {onboarding_client.tc_accepted ? (
                  <span className="text-xs bg-green-100 text-green-800 font-semibold px-2.5 py-1 rounded-full">✓ T&amp;C Accepted</span>
                ) : (
                  <span className="text-xs bg-yellow-100 text-yellow-800 font-semibold px-2.5 py-1 rounded-full">⏳ T&amp;C Pending</span>
                )}
              </div>
              <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <Row label="Name"              value={onboarding_client.name} />
                <Row label="Phone"             value={onboarding_client.phone} />
                <Row label="Email"             value={onboarding_client.email} />
                <Row label="Address"           value={onboarding_client.address} />
                <Row label="Guardian Name"     value={onboarding_client.local_guardian_name} />
                <Row label="Guardian Contact"  value={onboarding_client.local_guardian_contact} />
                <Row label="Emergency Name"    value={onboarding_client.emergency_contact_name} />
                <Row label="Emergency Phone"   value={onboarding_client.emergency_contact_phone} />
                {onboarding_client.tc_accepted && (
                  <Row label="T&C Accepted" value={`${fmt.datetime(onboarding_client.tc_accepted_at ?? null)} (v${onboarding_client.tc_version})`} />
                )}
                {onboarding_client.completed_at && (
                  <Row label="Completed At" value={fmt.datetime(onboarding_client.completed_at)} />
                )}
              </div>
              {onboarding_client.notes && (
                <div className="mt-3 pt-3 border-t border-gray-100">
                  <div className="text-xs font-semibold text-gray-400 uppercase mb-1">Notes</div>
                  <p className="text-sm text-gray-700">{onboarding_client.notes}</p>
                </div>
              )}
            </div>
          )}

          {/* Onboarding Pets */}
          {onboarding_pets.length > 0 && (
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="font-semibold text-gray-900 mb-3">Pet Information ({onboarding_pets.length})</h2>
              <div className="space-y-4">
                {onboarding_pets.map((pet, i) => (
                  <div key={pet.id} className="border border-gray-100 rounded-lg p-3 bg-gray-50">
                    <div className="font-semibold text-gray-800 mb-2">
                      {pet.name || `Pet ${i + 1}`}
                      {pet.pet_type && <span className="ml-2 text-xs text-gray-500 font-normal">{pet.pet_type}</span>}
                      {pet.breed && <span className="ml-1 text-xs text-gray-500 font-normal">· {pet.breed}</span>}
                    </div>
                    <div className="grid sm:grid-cols-3 gap-x-4 gap-y-1.5 text-sm">
                      <Row label="Breed Size"   value={pet.breed_size} />
                      <Row label="Gender"       value={pet.gender} />
                      <Row label="Weight"       value={pet.weight_kg ? `${pet.weight_kg} kg` : undefined} />
                      <Row label="Birthday"     value={fmt.date(pet.birthday ?? null)} />
                      <Row label="Vaccination"  value={pet.vaccination_status} />
                      <Row label="Anti-Rabies"  value={fmt.date(pet.anti_rabies_date ?? null)} />
                      <Row label="DHPPiL"       value={fmt.date(pet.dhppil_date ?? null)} />
                      <Row label="Kennel Cough" value={fmt.date(pet.kennel_cough_date ?? null)} />
                      <Row label="Vet"          value={pet.vet_name && pet.vet_contact ? `${pet.vet_name} · ${pet.vet_contact}` : pet.vet_name} />
                    </div>
                    {(pet.medication_detail || pet.preferences_or_allergies || pet.additional_notes) && (
                      <div className="mt-2 pt-2 border-t border-gray-200 space-y-1">
                        {pet.medication_detail && <p className="text-xs text-gray-600"><span className="font-medium">Medication:</span> {pet.medication_detail}</p>}
                        {pet.preferences_or_allergies && <p className="text-xs text-gray-600"><span className="font-medium">Allergies:</span> {pet.preferences_or_allergies}</p>}
                        {pet.additional_notes && <p className="text-xs text-gray-600"><span className="font-medium">Notes:</span> {pet.additional_notes}</p>}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Documents */}
          {documents.length > 0 && (
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="font-semibold text-gray-900 mb-3">Documents ({documents.length})</h2>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                {documents.map(doc => (
                  <a
                    key={doc.id}
                    href={doc.file_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="border border-gray-200 rounded-lg p-2 bg-gray-50 hover:border-blue-300 hover:bg-blue-50 transition-colors text-center block"
                  >
                    {doc.file_mime?.startsWith('image/') ? (
                      <img src={doc.file_url} alt={doc.label ?? doc.doc_type} className="w-full h-20 object-cover rounded mb-1.5" />
                    ) : (
                      <div className="w-full h-20 flex items-center justify-center text-3xl">📄</div>
                    )}
                    <div className="text-xs text-gray-600 font-medium truncate">{doc.label || doc.doc_type}</div>
                    <div className="text-xs text-gray-400 mt-0.5">{doc.doc_type.replace(/_/g, ' ')}</div>
                  </a>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Right column — Notes */}
        <div className="space-y-4">
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h2 className="font-semibold text-gray-900 mb-3">Internal Notes</h2>
            <div className="space-y-2 max-h-72 overflow-y-auto mb-3">
              {notes.length === 0 && (
                <p className="text-sm text-gray-400">No notes yet.</p>
              )}
              {notes.map(note => (
                <div key={note.id} className="bg-gray-50 rounded-lg p-2.5 text-sm border border-gray-100">
                  <p className="text-gray-800 whitespace-pre-wrap">{note.note}</p>
                  <div className="text-xs text-gray-400 mt-1">
                    {note.created_by_name} · {fmt.datetime(note.created_at)}
                  </div>
                </div>
              ))}
            </div>
            {!isTerminal && (
              <div>
                <textarea
                  ref={noteRef}
                  value={noteText}
                  onChange={e => setNoteText(e.target.value)}
                  placeholder="Add internal note…"
                  rows={3}
                  className="form-input w-full text-sm resize-none mb-2"
                />
                <button
                  onClick={addNote}
                  disabled={addingNote || !noteText.trim()}
                  className="w-full text-sm bg-blue-800 text-white py-1.5 rounded-lg hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed font-medium"
                >
                  {addingNote ? 'Adding…' : 'Add Note'}
                </button>
              </div>
            )}
          </div>

          {/* Quick status */}
          {!isTerminal && (
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h2 className="font-semibold text-gray-900 mb-3">Status</h2>
              <div className="space-y-1.5">
                {(['NEW','CONTACTED','ONBOARDING_SENT','ONBOARDING_COMPLETED','READY_FOR_REVIEW'] as InquiryStatus[]).map(s => (
                  <button
                    key={s}
                    onClick={() => updateStatus(s)}
                    className={`w-full text-left text-xs px-3 py-2 rounded-lg border transition-colors ${
                      inquiry.status === s
                        ? STATUS_COLORS[s] + ' border-current font-semibold'
                        : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'
                    }`}
                  >
                    {STATUS_LABELS[s]}
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Convert Modal */}
      {showConvertModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-4">Convert to Client</h2>

            {dupChecking && <p className="text-gray-500 text-sm mb-4">Checking for duplicate clients…</p>}

            {dupCheckResult?.duplicate_found && dupCheckResult.client && (
              <div className="bg-amber-50 border border-amber-300 rounded-xl p-4 mb-4">
                <div className="font-semibold text-amber-900 mb-1">⚠ Existing Client Found</div>
                <div className="text-sm text-amber-800">
                  Matched by <strong>{dupCheckResult.client.match_type === 'phone' ? 'phone' : 'email'}</strong>:
                </div>
                <div className="bg-white rounded-lg p-3 mt-2 border border-amber-200">
                  <div className="font-semibold">{dupCheckResult.client.name}</div>
                  <div className="text-sm text-gray-500">{dupCheckResult.client.phone} · {dupCheckResult.client.branch_name}</div>
                  <div className="text-sm text-gray-500">{dupCheckResult.client.pet_count} pet(s) · {dupCheckResult.client.booking_count} booking(s)</div>
                  <div className="flex gap-2 mt-2">
                    <Link
                      to={`/clients/${dupCheckResult.client.id}`}
                      className="text-xs bg-blue-800 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700"
                      onClick={() => setShowConvertModal(false)}
                    >
                      Open Existing Client
                    </Link>
                    <Link
                      to={`/bookings/new?client_id=${dupCheckResult.client.id}`}
                      className="text-xs bg-green-700 text-white px-3 py-1.5 rounded-lg hover:bg-green-600"
                      onClick={() => setShowConvertModal(false)}
                    >
                      Create Booking
                    </Link>
                  </div>
                </div>
                <p className="text-xs text-amber-700 mt-2 font-medium">
                  You may still continue conversion to create a new client record. Only do this if appropriate.
                </p>
              </div>
            )}

            {!dupChecking && !dupCheckResult?.duplicate_found && (
              <div className="bg-green-50 border border-green-200 rounded-lg p-3 mb-4 text-sm text-green-800">
                ✓ No duplicate client found. Safe to convert.
              </div>
            )}

            <div className="mb-4">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Assign to Branch <span className="text-red-500">*</span>
              </label>
              <select
                value={convertBranchId}
                onChange={e => setConvertBranchId(Number(e.target.value))}
                className="form-input w-full text-sm"
              >
                <option value={0}>— Select branch —</option>
                {branches.map(b => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </div>

            {convertError && (
              <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3 mb-4">
                {convertError}
              </div>
            )}

            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setShowConvertModal(false)}
                className="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200"
              >
                Cancel
              </button>
              <button
                onClick={handleConvert}
                disabled={converting || dupChecking || !convertBranchId}
                className="text-sm bg-green-700 text-white px-4 py-2 rounded-lg hover:bg-green-600 disabled:opacity-40 disabled:cursor-not-allowed font-semibold"
              >
                {converting ? 'Converting…' : 'Confirm — Convert to Client'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showRejectModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-4">Reject Inquiry</h2>
            <textarea
              value={rejectReason}
              onChange={e => setRejectReason(e.target.value)}
              placeholder="Reason for rejection (optional)"
              rows={3}
              className="form-input w-full text-sm mb-4"
            />
            <div className="flex gap-3 justify-end">
              <button onClick={() => setShowRejectModal(false)} className="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg">Cancel</button>
              <button
                onClick={handleReject}
                disabled={rejecting}
                className="text-sm bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 disabled:opacity-40 font-semibold"
              >
                {rejecting ? 'Rejecting…' : 'Reject Inquiry'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value?: string | null | number }) {
  if (!value && value !== 0) return null
  return (
    <div>
      <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide">{label}</div>
      <div className="text-sm text-gray-800 mt-0.5">{value}</div>
    </div>
  )
}
