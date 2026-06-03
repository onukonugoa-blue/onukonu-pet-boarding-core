import { NavLink } from 'react-router-dom'

interface NavItem {
  to: string
  label: string
  icon: JSX.Element
  roles?: string[]
  end?: boolean
}

function HomeIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
    </svg>
  )
}
function ClientsIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>
  )
}
function BookingsIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
    </svg>
  )
}
function KennelIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
    </svg>
  )
}
function InvoicesIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
  )
}
function InquiriesIcon() {
  return (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
  )
}

const BOTTOM_NAV: NavItem[] = [
  { to: '/',          label: 'Home',      icon: <HomeIcon />,      end: true },
  { to: '/clients',   label: 'Clients',   icon: <ClientsIcon />,   roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/bookings',  label: 'Bookings',  icon: <BookingsIcon />,  roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/inquiries', label: 'Inquiries', icon: <InquiriesIcon />, roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/invoices',  label: 'Invoices',  icon: <InvoicesIcon />,  roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
]

function getVisibleBottomNav(): NavItem[] {
  const roles: string[] = window.OPB?.user?.roles ?? []
  const isAdmin = roles.includes('administrator')
  if (isAdmin) return BOTTOM_NAV
  return BOTTOM_NAV.filter((item) => {
    if (!item.roles) return true
    return item.roles.some((r) => roles.includes(r))
  })
}

export default function BottomNav() {
  const items = getVisibleBottomNav()

  return (
    <nav className="md:hidden fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-gray-200 flex items-stretch safe-bottom" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          className={({ isActive }) =>
            `flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-xs font-medium transition-colors min-w-0 ${
              isActive
                ? 'text-blue-600'
                : 'text-gray-500 hover:text-gray-700'
            }`
          }
        >
          {({ isActive }) => (
            <>
              <span className={isActive ? 'text-blue-600' : 'text-gray-400'}>
                {item.icon}
              </span>
              <span className="truncate max-w-full px-0.5">{item.label}</span>
            </>
          )}
        </NavLink>
      ))}
    </nav>
  )
}
