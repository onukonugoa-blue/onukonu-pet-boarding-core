import { useEffect, useRef, useState } from 'react'
import { importApi, ImportResult, ImportDiagnostics, MigrationHistoryEntry } from '../api/settings'

const BRANCHES = ['H2', 'H3', 'H4']

const ENTITIES = [
  {
    key: 'clients',
    label: 'Clients & Pets',
    accept: '.csv',
    branch: false,
    cols: 'Name, Phone, Email, Address, Pet Name, Pet Type, Breed, Breed Size, Gender, Home Outlet, Onboarding Date, Pet ID',
  },
  {
    key: 'pets',
    label: 'Pets (standalone)',
    accept: '.csv',
    branch: false,
    cols: 'Phone, Pet Name, Pet Type, Breed, Breed Size, Gender, Birthday, Weight, Pet ID',
  },
  {
    key: 'bookings',
    label: 'Bookings',
    accept: '.csv,.xlsx',
    branch: true,
    cols: 'Booking ID, Booking Date, Phone, Payment Status, Total Billing Amount, Service Types, Booking Source, Notes',
  },
  {
    key: 'invoices',
    label: 'Invoices',
    accept: '.csv,.xlsx',
    branch: true,
    cols: 'Invoice No, Invoice Date, Booking ID, Revenue, Base Amount, Add-On Amount, Discount Amount, Paid, Due, Payment Mode',
  },
  {
    key: 'payments',
    label: 'Payments',
    accept: '.csv,.xlsx',
    branch: true,
    cols: 'Time, Amount, Mode, Invoice ID',
  },
  {
    key: 'expenses',
    label: 'Expenses',
    accept: '.csv,.xlsx',
    branch: true,
    cols: 'Expense, Time, Mode, Category, Amount, Amount (Inc. Tax)',
  },
  {
    key: 'services',
    label: 'Boarding Catalogue',
    accept: '.csv',
    branch: true,
    cols: 'Catalogue Name, Boarding Type Split Factor, Pet Type Split Factor, Row Type, Amount, Discount Type, Breed Size, Min Pets, Days, Extra Info',
  },
  {
    key: 'addons',
    label: 'Add-on Services',
    accept: '.csv',
    branch: true,
    cols: 'Name, Description, Type, Base Amount, Visibility Status',
  },
]

const REASON_LABELS: Record<string, string> = {
  missing_phone:          'Missing phone',
  missing_name:           'Missing name',
  missing_pet_name:       'Missing pet name',
  branch_not_found:       'Branch not found',
  missing_branch:         'Branch required',
  duplicate:              'Duplicate (already in DB)',
  client_not_found:       'Client not found',
  booking_not_found:      'Booking not found',
  invoice_not_found:      'Invoice not found',
  missing_invoice_no:     'Missing invoice number',
  missing_invoice_id:     'Missing invoice ID',
  missing_amount:         'Missing amount',
  invalid_date:           'Invalid date format',
  invalid_datetime:       'Invalid date/time format',
  missing_description:    'Missing description',
  missing_catalogue_name: 'Missing catalogue name',
  missing_row_type:       'Missing row type',
  invalid_boarding_type:  'Invalid boarding type',
}

function DiagnosticsPanel({ d }: { d: ImportDiagnostics }) {
  const { headers, skip_reasons, skipped_rows, note } = d
  const hasSkipReasons = Object.values(skip_reasons).some((n) => n > 0)

  return (
    <div className="mt-4 space-y-4 text-sm">
      <details open>
        <summary className="cursor-pointer font-semibold text-gray-700 select-none">
          Detected headers ({headers.header_count})
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

      <details open>
        <summary className="cursor-pointer font-semibold text-gray-700 select-none">
          Column mapping
        </summary>
        <table className="data-table mt-2 text-xs w-full">
          <thead>
            <tr><th>Field</th><th>Status</th><th>Matched header</th><th>Aliases searched</th></tr>
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
                <td className="text-gray-400 text-xs">{info.searched.join(', ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="mt-2 text-xs text-gray-500">
          Branch codes in DB:{' '}
          <strong>{headers.branch_codes_in_db.join(', ') || 'none'}</strong>
        </div>
      </details>

      {hasSkipReasons && (
        <details open>
          <summary className="cursor-pointer font-semibold text-gray-700 select-none">
            Skip reasons
          </summary>
          <table className="data-table mt-2 text-xs w-full">
            <thead><tr><th>Reason</th><th className="text-right">Rows</th></tr></thead>
            <tbody>
              {Object.entries(skip_reasons).map(([reason, count]) =>
                count > 0 ? (
                  <tr key={reason}>
                    <td>{REASON_LABELS[reason] ?? reason}</td>
                    <td className="text-right font-mono font-semibold text-red-600">{count}</td>
                  </tr>
                ) : null
              )}
            </tbody>
          </table>
        </details>
      )}

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

function HistoryTab({ entries }: { entries: MigrationHistoryEntry[] }) {
  if (!entries.length) {
    return <p className="text-sm text-gray-400 mt-4">No import runs recorded yet.</p>
  }
  return (
    <div className="mt-4 space-y-2">
      {entries.map((e, i) => (
        <div key={i} className="border rounded-lg p-3 text-sm">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div className="flex items-center gap-2">
              <span className="badge-blue font-mono">{e.entity}</span>
              {e.context?.branch_id != null && (
                <span className="badge-gray text-xs">branch_id={String(e.context?.branch_id ?? "")}</span>
              )}
            </div>
            <span className="text-xs text-gray-400">{e.timestamp}</span>
          </div>
          <div className="mt-1.5 flex gap-4 text-xs text-gray-600">
            <span>Total: <strong>{e.total}</strong></span>
            <span className="text-green-700">Imported: <strong>{e.imported}</strong></span>
            <span className="text-red-600">Skipped: <strong>{e.skipped}</strong></span>
          </div>
          {e.errors.length > 0 && (
            <details className="mt-1">
              <summary className="cursor-pointer text-xs text-red-600">
                {e.errors.length} error{e.errors.length > 1 ? 's' : ''}
              </summary>
              <ul className="mt-1 text-xs space-y-0.5 pl-2">
                {e.errors.map((err, j) => <li key={j} className="text-red-700">• {err}</li>)}
              </ul>
            </details>
          )}
        </div>
      ))}
    </div>
  )
}

export default function Import() {
  const [status,  setStatus]  = useState<Record<string, number>>({})
  const [history, setHistory] = useState<MigrationHistoryEntry[]>([])
  const [tab,     setTab]     = useState<'import' | 'history'>('import')
  const [entity,  setEntity]  = useState('clients')
  const [branch,  setBranch]  = useState('')
  const [file,    setFile]    = useState<File | null>(null)
  const [running, setRunning] = useState(false)
  const [result,  setResult]  = useState<ImportResult | null>(null)
  const [phase,   setPhase]   = useState<'idle' | 'preview' | 'done'>('idle')
  const fileRef = useRef<HTMLInputElement>(null)

  const ent = ENTITIES.find((e) => e.key === entity)!

  const loadStatus  = () => importApi.status().then(setStatus).catch(console.error)
  const loadHistory = () => importApi.history().then(setHistory).catch(console.error)

  useEffect(() => { loadStatus(); loadHistory() }, [])

  const reset = () => {
    setFile(null); setResult(null); setPhase('idle')
    if (fileRef.current) fileRef.current.value = ''
  }

  const handleEntityChange = (key: string) => {
    setEntity(key); setBranch(''); reset()
  }

  const handleDryRun = async () => {
    if (!file) return
    if (ent.branch && !branch) { alert('Select a branch for this entity.'); return }
    setRunning(true); setResult(null)
    try {
      const r = await importApi.dryRun(entity, file, branch || undefined)
      setResult(r); setPhase('preview')
    } catch (e: any) { alert(e.message) }
    finally { setRunning(false) }
  }

  const handleRun = async () => {
    if (!file) return
    if (!confirm(`Import ${result?.imported ?? '?'} records into ${ent.label}? This cannot be undone.`)) return
    setRunning(true)
    try {
      const r = await importApi.run(entity, file, branch || undefined)
      setResult(r); setPhase('done')
      await Promise.all([loadStatus(), loadHistory()])
    } catch (e: any) { alert(e.message) }
    finally { setRunning(false) }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Migration Engine</h1>
      </div>

      {/* DB counts */}
      <div className="card mb-5">
        <h2 className="font-semibold border-b pb-2 mb-3">Current Database</h2>
        <div className="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-9 gap-3">
          {Object.entries(status).map(([k, v]) => (
            <div key={k} className="text-center">
              <div className="text-xl font-bold text-blue-700">{v}</div>
              <div className="text-xs text-gray-500 capitalize">{k}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 mb-4">
        {(['import', 'history'] as const).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`px-4 py-1.5 rounded-md text-sm font-medium capitalize transition-colors ${
              tab === t
                ? 'bg-blue-600 text-white'
                : 'bg-white border text-gray-600 hover:bg-gray-50'
            }`}
          >
            {t === 'history' ? `History (${history.length})` : 'Import'}
          </button>
        ))}
      </div>

      {tab === 'history' && (
        <div className="card max-w-3xl">
          <h2 className="font-semibold border-b pb-2 mb-2">Import History</h2>
          <HistoryTab entries={history} />
        </div>
      )}

      {tab === 'import' && (
        <div className="card max-w-3xl">
          <h2 className="font-semibold border-b pb-2 mb-4">Import File</h2>

          <div className="form-group">
            <label className="form-label">Entity</label>
            <select
              className="form-select"
              value={entity}
              onChange={(e) => handleEntityChange(e.target.value)}
            >
              {ENTITIES.map((e) => <option key={e.key} value={e.key}>{e.label}</option>)}
            </select>
          </div>

          {ent.branch && (
            <div className="form-group">
              <label className="form-label">
                Branch <span className="text-red-500">*</span>
              </label>
              <select
                className="form-select"
                value={branch}
                onChange={(e) => setBranch(e.target.value)}
              >
                <option value="">— select branch —</option>
                {BRANCHES.map((b) => <option key={b} value={b}>{b}</option>)}
              </select>
              <p className="text-xs text-gray-400 mt-1">
                Required so records are assigned to the correct branch.
              </p>
            </div>
          )}

          <div className="alert-info mb-3 text-xs">
            <strong>Expected columns:</strong> {ent.cols}
            <br />
            <span className="text-gray-500">
              Accepts: {ent.accept.replace(/,/g, ' or ')}
            </span>
          </div>

          <div className="form-group">
            <label className="form-label">File</label>
            <input
              ref={fileRef}
              type="file"
              accept={ent.accept}
              className="form-input"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setResult(null)
                setPhase('idle')
              }}
            />
          </div>

          {phase === 'idle' && (
            <button
              onClick={handleDryRun}
              disabled={!file || running || (ent.branch && !branch)}
              className="btn-primary"
            >
              {running ? 'Analysing…' : 'Preview Import'}
            </button>
          )}

          {result && (
            <div
              className={`mt-3 p-3 rounded-md border ${
                result.error
                  ? 'bg-red-50 border-red-200'
                  : phase === 'done'
                    ? 'bg-green-50 border-green-200'
                    : 'bg-blue-50 border-blue-200'
              }`}
            >
              {result.error ? (
                <div className="text-sm text-red-700 font-medium">{result.error}</div>
              ) : (
                <>
                  <div className="font-semibold mb-2">
                    {phase === 'done' ? 'Import complete!' : 'Dry-run preview'}
                  </div>
                  <div className="grid grid-cols-3 gap-3 text-sm mb-2">
                    <div className="text-center">
                      <div className="text-lg font-bold text-gray-800">{result.total}</div>
                      <div className="text-xs text-gray-500">Total rows</div>
                    </div>
                    <div className="text-center">
                      <div className="text-lg font-bold text-green-700">{result.imported}</div>
                      <div className="text-xs text-gray-500">
                        {phase === 'done' ? 'Imported' : 'Will import'}
                      </div>
                    </div>
                    <div className="text-center">
                      <div className="text-lg font-bold text-red-600">{result.skipped}</div>
                      <div className="text-xs text-gray-500">
                        {phase === 'done' ? 'Skipped' : 'Will skip'}
                      </div>
                    </div>
                  </div>

                  {result.diagnostics && phase !== 'done' && (
                    <DiagnosticsPanel d={result.diagnostics} />
                  )}

                  {!result.diagnostics && result.errors.length > 0 && (
                    <details className="mt-2">
                      <summary className="cursor-pointer text-xs">
                        Show errors ({result.errors.length})
                      </summary>
                      <ul className="mt-1 text-xs space-y-0.5">
                        {result.errors.map((e, i) => (
                          <li key={i} className="text-red-700">• {e}</li>
                        ))}
                      </ul>
                    </details>
                  )}
                </>
              )}
            </div>
          )}

          {phase === 'preview' && !result?.error && (
            <div className="flex gap-3 mt-4">
              <button
                onClick={handleRun}
                disabled={running || (result?.imported ?? 0) === 0}
                className="btn-primary"
              >
                {running ? 'Importing…' : `Import ${result?.imported ?? ''} Records`}
              </button>
              <button onClick={reset} className="btn-secondary">Cancel</button>
            </div>
          )}

          {(phase === 'done' || result?.error) && (
            <button onClick={reset} className="btn-secondary mt-4">
              Import Another
            </button>
          )}
        </div>
      )}
    </div>
  )
}
