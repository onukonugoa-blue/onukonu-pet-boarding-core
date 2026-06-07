import BranchSelector from './BranchSelector'
import { usePWAInstall } from '../hooks/usePWAInstall'

interface Props { onMenuToggle: () => void }

export default function TopBar({ onMenuToggle }: Props) {
  const user      = window.OPB?.user
  const logoutUrl = window.OPB?.logoutUrl ?? '/wp-login.php?action=logout'

  const { installState, triggerInstall } = usePWAInstall()

  return (
    <header
      className="bg-blue-900 text-white flex items-center px-4 gap-3 shrink-0 shadow-md z-20"
      style={{ paddingTop: 'env(safe-area-inset-top)', minHeight: '3rem' }}
    >
      <button
        onClick={onMenuToggle}
        className="lg:hidden p-1 rounded hover:bg-blue-700"
        aria-label="Toggle menu"
      >
        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>

      <div className="flex items-center gap-2 mr-auto">
        <svg className="w-5 h-5 text-blue-300 shrink-0" viewBox="0 0 192 192" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
          <ellipse cx="62"  cy="76"  rx="17" ry="21" transform="rotate(-15 62 76)"/>
          <ellipse cx="130" cy="76"  rx="17" ry="21" transform="rotate(15 130 76)"/>
          <ellipse cx="85"  cy="62"  rx="15" ry="19" transform="rotate(-5 85 62)"/>
          <ellipse cx="107" cy="62"  rx="15" ry="19" transform="rotate(5 107 62)"/>
          <ellipse cx="96"  cy="122" rx="36" ry="29"/>
        </svg>
        <span className="font-semibold text-sm hidden sm:block">Onukonu Pet Boarding</span>
      </div>

      <BranchSelector />

      {installState === 'installable' && (
        <button
          onClick={triggerInstall}
          title="Install OPB as an app on this device"
          className="flex items-center gap-1.5 text-xs text-blue-200 hover:text-white bg-blue-700 hover:bg-blue-600 rounded px-2 py-1 transition-colors shrink-0"
        >
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          <span className="hidden sm:inline">Install</span>
        </button>
      )}

      <div className="flex items-center gap-3 text-sm text-blue-200">
        <div className="flex items-center gap-1.5">
          <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          <span className="hidden sm:block">{user?.name ?? 'User'}</span>
        </div>

        <a
          href={logoutUrl}
          title="Log out"
          className="flex items-center gap-1 hover:text-white transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          <span className="hidden md:block text-xs">Log out</span>
        </a>
      </div>
    </header>
  )
}
