import { useEffect, useState } from 'react'

export interface PortalHealthResult {
  ok: boolean
  version: string
  checks: Record<string, boolean>
  reasons: string[]
}

export type HealthState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'healthy' }
  | { status: 'unhealthy'; result: PortalHealthResult }
  | { status: 'error' }

const ADMIN_ROLES = ['opb_super_admin', 'administrator']

function isAdmin(): boolean {
  const roles: string[] = window.OPB?.user?.roles ?? []
  return roles.some((r) => ADMIN_ROLES.includes(r))
}

const SESSION_DISMISSED_KEY = 'opb_health_banner_dismissed'

export function usePortalHealth() {
  const [state, setState] = useState<HealthState>({ status: 'idle' })
  const [dismissed, setDismissed] = useState<boolean>(
    () => sessionStorage.getItem(SESSION_DISMISSED_KEY) === '1'
  )

  useEffect(() => {
    // Only check for admin/super-admin roles — regular staff never see this
    if (!isAdmin()) return

    setState({ status: 'loading' })

    const apiBase: string = window.OPB?.apiBase ?? '/wp-json/opb/v1'
    const nonce: string   = window.OPB?.nonce  ?? ''

    fetch(`${apiBase}/health/portal`, {
      headers: {
        'X-WP-Nonce': nonce,
        'Accept':     'application/json',
      },
      credentials: 'include',
    })
      .then(async (res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        return res.json() as Promise<PortalHealthResult>
      })
      .then((result) => {
        if (result.ok) {
          setState({ status: 'healthy' })
        } else {
          setState({ status: 'unhealthy', result })
        }
      })
      .catch(() => {
        setState({ status: 'error' })
      })
  }, [])

  const dismiss = () => {
    sessionStorage.setItem(SESSION_DISMISSED_KEY, '1')
    setDismissed(true)
  }

  return { state, dismissed, dismiss }
}
