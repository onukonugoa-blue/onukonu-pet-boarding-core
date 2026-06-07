import { useEffect, useRef, useState } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

declare global {
  interface Window {
    __opbDeferredInstall: BeforeInstallPromptEvent | null
  }
}

export type InstallState = 'unsupported' | 'installable' | 'installed'

/**
 * 'ios'   — iPhone / iPad Safari (no beforeinstallprompt; needs Share → Add to Home Screen)
 * 'other' — Firefox Android, Samsung Internet, desktop, etc.
 */
export type Platform = 'ios' | 'other'

export interface UsePWAInstallResult {
  installState: InstallState
  platform: Platform
  triggerInstall: () => Promise<void>
}

function isStandalone(): boolean {
  if (typeof window === 'undefined') return false
  if (window.matchMedia('(display-mode: standalone)').matches) return true
  if ((navigator as Navigator & { standalone?: boolean }).standalone === true) return true
  return false
}

function detectPlatform(): Platform {
  if (typeof navigator === 'undefined') return 'other'
  return /iphone|ipad|ipod/i.test(navigator.userAgent) ? 'ios' : 'other'
}

export function usePWAInstall(): UsePWAInstallResult {
  const promptRef = useRef<BeforeInstallPromptEvent | null>(null)
  const [installState, setInstallState] = useState<InstallState>(() =>
    isStandalone() ? 'installed' : 'unsupported'
  )
  const platform = detectPlatform()

  useEffect(() => {
    if (isStandalone()) {
      setInstallState('installed')
      return
    }

    /*
     * Chrome fires beforeinstallprompt very early — before React mounts and
     * before useEffect runs. The inline script in the portal <head> captures
     * the event synchronously and stores it on window.__opbDeferredInstall.
     * We read that stored event first, then also listen for any future events.
     */
    const deferred = window.__opbDeferredInstall
    if (deferred) {
      promptRef.current = deferred
      window.__opbDeferredInstall = null
      setInstallState('installable')
    }

    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault()
      promptRef.current = e as BeforeInstallPromptEvent
      setInstallState('installable')
    }

    const handleAppInstalled = () => {
      promptRef.current = null
      window.__opbDeferredInstall = null
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

  return { installState, platform, triggerInstall }
}
