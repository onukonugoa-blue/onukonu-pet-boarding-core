import { useEffect, useRef, useState } from 'react'
import { importApi } from '../api/settings'

const ENTITIES = [
  { key: 'clients',   label: 'Clients & Pets',  cols: 'Name, Phone, Email, Address, Pet Name, Pet Type, Breed, Breed Size, Gender, Home Outlet, Onboarding Date, Pet ID' },
  { key: 'bookings',  label: 'Bookings',         cols: 'Phone, Booking Date, Payment Status, Total, branch_id, ID' },
  { key: 'expenses',  label: 'Expenses',         cols: 'Description, Amount, Mode, Category, Date, Branch' },
]

export default function Import() {
  const [status, setStatus] = useState<Record<string, number>>({})
  const [entity, setEntity] = useState('clients')
  const [file, setFile] = useState<File | null>(null)
  const [running, setRunning] = useState(false)
  const [result, setResult] = useState<{ imported: number; skipped: number; errors: string[]; total: number; dry_run?: boolean } | null>(null)
  const [phase, setPhase] = useState<'idle' | 'preview' | 'done'>('idle')
  const fileRef = useRef<HTMLInputElement>(null)
  const ent = ENTITIES.find((e) => e.key === entity)!

  const loadStatus = () => importApi.status().then(setStatus).catch(console.error)
  useEffect(() => { loadStatus() }, [])

  const handleDryRun = async () => {
    if (!file) return
    setRunning(true)
    setResult(null)
    try {
      const r = await importApi.dryRun(entity, file)
      setResult(r)
      setPhase('preview')
    } catch (e: any) { alert(e.message) }
    finally { setRunning(false) }
  }

  const handleRun = async () => {
    if (!file) return
    if (!confirm(`This will import ${result?.imported ?? '?'} records into ${ent.label}. Continue?`)) return
    setRunning(true)
    try {
      const r = await importApi.run(entity, file)
      setResult(r)
      setPhase('done')
      await loadStatus()
    } catch (e: any) { alert(e.message) }
    finally { setRunning(false) }
  }

  const reset = () => {
    setFile(null); setResult(null); setPhase('idle')
    if (fileRef.current) fileRef.current.value = ''
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Import Engine</h1>
      </div>

      {/* Current counts */}
      <div className="card mb-5">
        <h2 className="font-semibold border-b pb-2 mb-3">Current Database</h2>
        <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-7 gap-3">
          {Object.entries(status).map(([k, v]) => (
            <div key={k} className="text-center">
              <div className="text-xl font-bold text-blue-700">{v}</div>
              <div className="text-xs text-gray-500 capitalize">{k}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Import form */}
      <div className="card max-w-xl">
        <h2 className="font-semibold border-b pb-2 mb-4">Import CSV</h2>

        <div className="form-group">
          <label className="form-label">Entity</label>
          <select className="form-select" value={entity} onChange={(e) => { setEntity(e.target.value); reset() }}>
            {ENTITIES.map((e) => <option key={e.key} value={e.key}>{e.label}</option>)}
          </select>
        </div>

        <div className="alert-info mb-3 text-xs">
          <strong>Expected columns:</strong> {ent.cols}
        </div>

        <div className="form-group">
          <label className="form-label">CSV File</label>
          <input
            ref={fileRef}
            type="file"
            accept=".csv"
            className="form-input"
            onChange={(e) => { setFile(e.target.files?.[0] ?? null); setResult(null); setPhase('idle') }}
          />
        </div>

        {phase === 'idle' && (
          <button onClick={handleDryRun} disabled={!file || running} className="btn-primary">
            {running ? 'Analysing…' : 'Preview Import'}
          </button>
        )}

        {result && (
          <div className={phase === 'done' ? 'alert-success mt-3' : 'alert-info mt-3'}>
            <div className="font-semibold mb-1">{phase === 'done' ? 'Import complete!' : 'Preview (dry-run)'}</div>
            <div>Total rows: <strong>{result.total}</strong></div>
            <div>Will import: <strong>{result.imported}</strong></div>
            <div>Will skip: <strong>{result.skipped}</strong></div>
            {result.errors.length > 0 && (
              <details className="mt-2">
                <summary className="cursor-pointer text-xs">Show errors ({result.errors.length})</summary>
                <ul className="mt-1 text-xs space-y-0.5">
                  {result.errors.map((e, i) => <li key={i} className="text-red-700">• {e}</li>)}
                </ul>
              </details>
            )}
          </div>
        )}

        {phase === 'preview' && (
          <div className="flex gap-3 mt-4">
            <button onClick={handleRun} disabled={running} className="btn-primary">
              {running ? 'Importing…' : `Import ${result?.imported ?? ''} Records`}
            </button>
            <button onClick={reset} className="btn-secondary">Cancel</button>
          </div>
        )}

        {phase === 'done' && (
          <button onClick={reset} className="btn-secondary mt-4">Import Another</button>
        )}
      </div>
    </div>
  )
}
