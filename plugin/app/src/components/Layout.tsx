import { ReactNode, useState } from 'react'
import Sidebar from './Sidebar'
import TopBar from './TopBar'
import BottomNav from './BottomNav'
import PortalHealthBanner from './PortalHealthBanner'

interface Props { children: ReactNode }

export default function Layout({ children }: Props) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex flex-col h-dvh overflow-hidden">
      <TopBar onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
      <PortalHealthBanner />
      <div className="flex flex-1 min-h-0">
        <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
        <main className="flex-1 min-h-0 overflow-y-auto bg-gray-50 p-4 lg:p-5">
          {children}
          {/* Clearance spacer — keeps content above the fixed BottomNav on mobile.
              Height = BottomNav visual height (4rem/64px) + device safe-area-inset-bottom.
              Must be a runtime calc(); a Tailwind pb-* class cannot express env(). */}
          <div
            className="md:hidden"
            style={{ height: 'calc(4rem + env(safe-area-inset-bottom, 0px))' }}
            aria-hidden="true"
          />
        </main>
      </div>
      <BottomNav />
    </div>
  )
}
