import { useEffect, useRef, useState } from 'react'
import { importApi, ImportResult, ImportDiagnostics } from '../api/settings'

const ENTITIES = [
  { key: 'clients',   label: 'Clients & Pets',  cols: 'Name, Phone, Email, Address, Pet Name, Pet Type, Breed, Breed Size, Gender, Home Outlet, Onboarding Date, Pet ID' },
  { key: 'bookings',  label: 'Bookings',         cols: 'Phone, Booking Date, Payment Status, Total, branch_id, ID' },
  { key: 'expenses',  label: 'Expenses',         cols: 'Description, Amount, Mode, Category, Date, Branch' },
]

const REASON_LABELS: Record<string, string> = {
  missing_phone:    'Missing phone',
  missing_name:     'Missing name',
  branch_not_found: 'Branch not found',
  duplicate:        'Duplicate (already in DB)',
}

function DiagnosticsPanel({ d }: { d: ImportDiagnostics }) {
  const { headers, skip_reasons, skipped_rows, note } = d
  const hasSkipReasons = Object.values(skip_reasons).some((n) => n > 0)

  return (
    <div className="mt-4 space-y-4 text-sm">

      {/* Detected headers */}
      <details open>
        <summary className="cursor-pointer font-semibold text-gray-700 select-none">
          Detected CSV headers ({headers.header_count})
        </summary>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {headers.headers_detected.map((h) => (
            <span key={h} className="badge-blue">{h}</span>
          ))}
        </div>
        {headers.missing_required.length > 0 && (
          <div className="mt-2 alert-error text-xs">
            <strong>Missing required columns:</strong>
            <ul className="mt-1 list-disc list-inside">
              {headers.missing_required.map((m) => <li key={m}>{m}</li>)}
            </ul>
          </div>
        )}
      </details>

      {/* Column mapping */}
      <details open>
        <summary className="cursor-pointer font-semibold text-gray-700 select-none">
          Column mapping
        </summary>
        <table className="data-table mt-2 text-xs w-full">
          <thead>
            <tr>
              <th>Field</th>
              <th>Status</th>
              <th>Matched header</th>
              <th>Aliases searched</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(headers.column_analysis).map(([field, info]) => (
              <tr key={field}>
                <td className="font-mono">{field}</td>
                <td>
                  {info.found
                    ? <span className="badge-green">✓ found</span>
                    : <span className="badge-gray">— not present</span>}
                </td>
                <td className="font-mono">{info.matched ?? '—'}</td>
                <td className="text-gray-400">{info.searched.join(', ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="mt-2 text-xs text-gray-500">
          Branch codes in DB: <strong>{headers.branch_codes_in_db.join(', ') || 'none'}</strong>
        </div>
      </details>

      {/* Skip reason tally */}
      {hasSkipReasons && (
        <details open>
          <summary className="cursor-pointer font-semibold text-gray-700 select-none">
            Skip reasons summary
          </summary>
          <table className="data-table mt-2 text-xs w-full">
            <thead>
              <tr><th>Reason</th><th className="text-right">Rows</th></tr>
            </thead>
            <tbody>
              {Object.entries(skip_reasons).map(([reason, count]) => (
                count > 0 && (
                  <tr key={reason}>
                    <td>{REASON_LABELS[reason] ?? reason}</td>
                    <td className="text-right font-mono font-semibold text-red-600">{count}</td>
                  </tr>
                )
              ))}
            </tbody>
          </table>
        </details>
      )}

      {/* Per-row skip detail */}
      {skipped_rows.length > 0 && (
        <details open>
          <summary className="cursor-pointer font-semibold text-gray-700 select-none">
            Skipped rows — first {skipped_rows.length} ({note})
          </summary>
          <div className="mt-2 space-y-1.5 max-h-72 overflow-y-auto">
            {skipped_rows.map((sr) => (
              <div key={sr.row} className="flex gap-2 text-xs border-b border-gray-100 pb-1">
                <span className="shrink-0 font-mono text-gray-400 w-14">Row {sr.row}</span>
                <span className="shrink-0 badge-red">{REASON_LABELS[sr.reason] ?? sr.reason}</span>
                <span className="text-gray-600">{sr.detail}</span>
              </div>
            ))}
          </div>
        </details>
      )}

    </div>
  )
}

export default function Import() {
  const [status, setStatus] = useState<Record<string, number>>({})
  const [entity, setEntity] = useState('clients')
  const [file, setFile] = useState<File | null>(null)
  const [running, setRunning] = useState(false)
  const [result, setResult] = useState<ImportResult | null>(null)
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
      <div className="card max-w-3xl">
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
          <div className={`mt-3 p-3 rounded-md border ${phase === 'done' ? 'bg-green-50 border-green-200' : 'bg-blue-50 border-blue-200'}`}>
            <div className="font-semibold mb-2">{phase === 'done' ? 'Import complete!' : 'Dry-run preview'}</div>

            <div className="grid grid-cols-3 gap-3 text-sm mb-2">
              <div className="text-center">
                <div className="text-lg font-bold text-gray-800">{result.total}</div>
                <div className="text-xs text-gray-500">Total rows</div>
              </div>
              <div className="text-center">
                <div className="text-lg font-bold text-green-700">{result.imported}</div>
                <div className="text-xs text-gray-500">Will import</div>
              </div>
              <div className="text-center">
                <div className="text-lg font-bold text-red-600">{result.skipped}</div>
                <div className="text-xs text-gray-500">Will skip</div>
              </div>
            </div>

            {/* Diagnostics panel (dry-run only) */}
            {result.diagnostics && phase !== 'done' && (
              <DiagnosticsPanel d={result.diagnostics} />
            )}

            {/* Legacy errors list — shown when no structured diagnostics */}
            {!result.diagnostics && result.errors.length > 0 && (
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
            <button onClick={handleRun} disabled={running || (result?.imported ?? 0) === 0} className="btn-primary">
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
