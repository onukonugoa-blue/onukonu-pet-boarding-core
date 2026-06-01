import { NavLink } from 'react-router-dom'
import { useEffect, useState } from 'react'

interface NavItem {
  to: string
  label: string
  icon: string
  roles?: string[]   // undefined = visible to all OPB roles
}

const ALL_LINKS: NavItem[] = [
  { to: '/',         label: 'Dashboard',    icon: '⊞' },
  { to: '/clients',  label: 'Clients',      icon: '👥', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/bookings', label: 'Bookings',     icon: '📋', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/kennel',   label: 'Kennel Board', icon: '🏠', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/invoices', label: 'Invoices',     icon: '🧾', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/tasks',    label: 'Tasks',        icon: '✓'  },
  { to: '/expenses', label: 'Expenses',     icon: '💰', roles: ['opb_branch_manager', 'opb_super_admin'] },
  { to: '/reports',  label: 'Reports',      icon: '📊', roles: ['opb_branch_manager', 'opb_super_admin'] },
  { to: '/settings', label: 'Settings',     icon: '⚙',  roles: ['opb_super_admin'] },
  { to: '/import',   label: 'Import',       icon: '📥', roles: ['opb_super_admin'] },
]

function getVisibleLinks(): NavItem[] {
  const roles: string[] = window.OPB?.user?.roles ?? []
  const isAdmin = roles.includes('administrator')
  if (isAdmin) return ALL_LINKS

  return ALL_LINKS.filter((link) => {
    if (!link.roles) return true                               // visible to all OPB roles
    return link.roles.some((r) => roles.includes(r))
  })
}

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>
  readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

interface Props { open: boolean; onClose: () => void }

export default function Sidebar({ open, onClose }: Props) {
  const [installPrompt, setInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled]         = useState(false)
  const links = getVisibleLinks()

  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault()
      setInstallPrompt(e as BeforeInstallPromptEvent)
    }
    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', () => setInstalled(true))
    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [])

  async function handleInstall() {
    if (!installPrompt) return
    await installPrompt.prompt()
    const { outcome } = await installPrompt.userChoice
    if (outcome === 'accepted') setInstalled(true)
    setInstallPrompt(null)
  }

  return (
    <>
      {open && (
        <div
          className="fixed inset-0 bg-black bg-opacity-40 z-30 lg:hidden"
          onClick={onClose}
        />
      )}
      <aside className={`
        fixed top-12 left-0 bottom-0 w-52 bg-blue-800 flex flex-col z-40 transition-transform duration-200
        lg:static lg:translate-x-0 lg:top-auto lg:bottom-auto lg:h-full lg:flex-shrink-0
        ${open ? 'translate-x-0' : '-translate-x-full'}
      `}>
        <nav className="flex-1 py-3 overflow-y-auto">
          {links.map((l) => (
            <NavLink
              key={l.to}
              to={l.to}
              end={l.to === '/'}
              onClick={onClose}
              className={({ isActive }) =>
                `sidebar-link mx-2 my-0.5 ${isActive ? 'sidebar-link-active' : 'sidebar-link-inactive'}`
              }
            >
              <span className="text-base w-5 text-center">{l.icon}</span>
              <span>{l.label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="p-3 border-t border-blue-700 space-y-2">
          {installPrompt && !installed && (
            <button
              onClick={handleInstall}
              className="w-full flex items-center gap-2 text-xs text-blue-200 hover:text-white bg-blue-700 hover:bg-blue-600 rounded px-2 py-1.5 transition-colors"
            >
              <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Install App
            </button>
          )}
          <p className="text-xs text-blue-400">v1.2.0</p>
        </div>
      </aside>
    </>
  )
}
