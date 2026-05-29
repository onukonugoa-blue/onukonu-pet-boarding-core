import BranchSelector from './BranchSelector'

interface Props { onMenuToggle: () => void }

export default function TopBar({ onMenuToggle }: Props) {
  const user = window.OPB?.user

  return (
    <header className="bg-blue-900 text-white h-12 flex items-center px-4 gap-3 shrink-0 shadow-md z-20">
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
        <span className="text-lg">🐾</span>
        <span className="font-semibold text-sm hidden sm:block">Onukonu Pet Boarding</span>
      </div>

      <BranchSelector />

      <div className="flex items-center gap-2 text-sm text-blue-200">
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
        <span className="hidden sm:block">{user?.name ?? 'User'}</span>
      </div>
    </header>
  )
}
