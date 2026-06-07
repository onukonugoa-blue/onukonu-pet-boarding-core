import { usePortalHealth } from '../hooks/usePortalHealth'

export default function PortalHealthBanner() {
  const { state, dismissed, dismiss } = usePortalHealth()

  if (dismissed) return null
  if (state.status !== 'unhealthy') return null

  const { reasons } = state.result

  return (
    <div
      role="alert"
      className="flex items-start gap-3 px-4 py-3 bg-amber-50 border-b border-amber-200 text-amber-900 text-sm"
    >
      <span className="text-lg leading-none mt-0.5" aria-hidden="true">⚠</span>

      <div className="flex-1 min-w-0">
        <p className="font-semibold">Portal routing issue detected</p>
        <ul className="mt-1 space-y-0.5 list-disc list-inside text-amber-800">
          {reasons.map((r, i) => (
            <li key={i}>{r}</li>
          ))}
        </ul>
        <p className="mt-1.5 text-amber-700">
          Quick fix: go to{' '}
          <a
            href={`${window.OPB?.adminUrl ?? '/wp-admin/admin.php'}?page=options-permalink.php`}
            target="_blank"
            rel="noreferrer"
            className="underline font-medium hover:text-amber-900"
          >
            Settings → Permalinks
          </a>{' '}
          and click <strong>Save Changes</strong>. Then reload this page.
        </p>
      </div>

      <button
        onClick={dismiss}
        className="flex-shrink-0 text-amber-600 hover:text-amber-900 transition-colors"
        aria-label="Dismiss routing warning"
        title="Dismiss for this session"
      >
        ✕
      </button>
    </div>
  )
}
