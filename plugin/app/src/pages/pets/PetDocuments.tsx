import { useEffect, useRef, useState } from 'react'
import { petsApi } from '../../api/pets'
import type { PetDocument } from '../../api/pets'
import { fmt } from '../../api/client'

interface Props { petId: number }

export default function PetDocuments({ petId }: Props) {
  const [docs, setDocs] = useState<PetDocument[]>([])
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')
  const fileRef = useRef<HTMLInputElement>(null)

  const load = () => petsApi.documents(petId).then(setDocs).catch(console.error)
  useEffect(() => { load() }, [petId])

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    setError('')
    setUploading(true)
    const fd = new FormData()
    fd.append('file', file)
    fd.append('doc_type', file.type.startsWith('image') ? 'photo' : 'vaccination')
    fd.append('label', file.name.split('.')[0])
    try {
      await petsApi.uploadDocument(petId, fd)
      await load()
    } catch (e: any) {
      setError(e.message ?? 'Upload failed')
    } finally {
      setUploading(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  const handleDelete = async (docId: number) => {
    if (!confirm('Delete this document?')) return
    await petsApi.deleteDocument(petId, docId)
    setDocs((prev) => prev.filter((d) => d.id !== docId))
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h3 className="font-semibold text-gray-900">Documents & Photos</h3>
        <label className="btn-primary cursor-pointer">
          {uploading ? 'Uploading…' : '+ Upload'}
          <input ref={fileRef} type="file" className="hidden" onChange={handleUpload} accept="image/*,.pdf,.doc,.docx" />
        </label>
      </div>
      {error && <div className="alert-error">{error}</div>}

      {docs.length === 0 ? (
        <div className="empty-state"><span className="text-4xl">📄</span><p>No documents uploaded yet</p></div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
          {docs.map((d) => (
            <div key={d.id} className="card flex flex-col items-center text-center group relative">
              {d.file_mime?.startsWith('image') ? (
                <a href={d.file_url} target="_blank" rel="noopener noreferrer">
                  <img src={d.file_url} alt={d.label ?? 'Doc'} className="w-full h-24 object-cover rounded-md mb-2" />
                </a>
              ) : (
                <a href={d.file_url} target="_blank" rel="noopener noreferrer" className="text-3xl mb-2">📄</a>
              )}
              <p className="text-xs text-gray-700 truncate w-full">{d.label ?? d.doc_type}</p>
              <p className="text-xs text-gray-400">{fmt.date(d.created_at)}</p>
              <button
                onClick={() => handleDelete(d.id)}
                className="absolute top-1 right-1 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition-opacity text-xs bg-white rounded-full p-0.5"
              >✕</button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
