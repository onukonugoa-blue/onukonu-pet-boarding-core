import { useEffect, useRef, useState } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export type InstallState = 'unsupported' | 'installable' | 'installed'

export interface UsePWAInstallResult {
  installState: InstallState
  triggerInstall: () => Promise<void>
}

function isStandalone(): boolean {
  if (typeof window === 'undefined') return false
  // Standard display-mode check
  if (window.matchMedia('(display-mode: standalone)').matches) return true
  // Safari on iOS
  if ((navigator as Navigator & { standalone?: boolean }).standalone === true) return true
  return false
}

export function usePWAInstall(): UsePWAInstallResult {
  const promptRef = useRef<BeforeInstallPromptEvent | null>(null)
  const [installState, setInstallState] = useState<InstallState>(() =>
    isStandalone() ? 'installed' : 'unsupported'
  )

  useEffect(() => {
    // Already running as installed PWA
    if (isStandalone()) {
      setInstallState('installed')
      return
    }

    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault()
      promptRef.current = e as BeforeInstallPromptEvent
      setInstallState('installable')
    }

    const handleAppInstalled = () => {
      promptRef.current = null
      setInstallState('installed')
    }

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt)
    window.addEventListener('appinstalled', handleAppInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt)
      window.removeEventListener('appinstalled', handleAppInstalled)
    }
  }, [])

  const triggerInstall = async (): Promise<void> => {
    if (!promptRef.current) return
    await promptRef.current.prompt()
    const { outcome } = await promptRef.current.userChoice
    if (outcome === 'accepted') {
      promptRef.current = null
      setInstallState('installed')
    }
  }

  return { installState, triggerInstall }
}
