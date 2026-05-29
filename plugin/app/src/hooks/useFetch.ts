import { useEffect, useState } from 'react'

export function useFetch<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const reload = () => {
    setLoading(true)
    setError(null)
    fn()
      .then(setData)
      .catch((e) => setError(e.message ?? 'Error'))
      .finally(() => setLoading(false))
  }

  useEffect(reload, deps)

  return { data, loading, error, reload }
}
