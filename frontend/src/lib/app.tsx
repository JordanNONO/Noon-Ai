import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, getToken, setToken, setUnauthorizedHandler } from './api'
import type { Bootstrap, User } from './types'

interface AppState {
  ready: boolean
  user: User | null
  boot: Bootstrap | null
  signIn: (token: string, user: User) => Promise<void>
  signOut: () => Promise<void>
  refresh: () => Promise<void>
  setUser: (u: User) => void
}

const Ctx = createContext<AppState | null>(null)

/** Une seule source de vérité pour l'utilisateur et les chiffres affichés partout. */
export function AppProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false)
  const [boot, setBoot] = useState<Bootstrap | null>(null)
  const [user, setUserState] = useState<User | null>(null)

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setBoot(null)
      setUserState(null)
      return
    }
    const b = await api.get<Bootstrap>('/bootstrap')
    setBoot(b)
    setUserState(b.user)
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(() => {
      setToken(null)
      setBoot(null)
      setUserState(null)
    })
    refresh().catch(() => setToken(null)).finally(() => setReady(true))
  }, [refresh])

  useEffect(() => {
    document.documentElement.classList.toggle('large-text', !!user?.large_text)
  }, [user?.large_text])

  const value = useMemo<AppState>(() => ({
    ready,
    user,
    boot,
    refresh,
    setUser: (u) => {
      setUserState(u)
      setBoot((b) => (b ? { ...b, user: u } : b))
    },
    signIn: async (token, u) => {
      setToken(token)
      setUserState(u)
      await refresh()
    },
    signOut: async () => {
      try {
        await api.post('/auth/logout')
      } catch {
        /* déjà déconnecté */
      }
      setToken(null)
      setBoot(null)
      setUserState(null)
    },
  }), [ready, user, boot, refresh])

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useApp(): AppState {
  const v = useContext(Ctx)
  if (!v) throw new Error('useApp hors de AppProvider')
  return v
}

/** Bonjour / Bon après-midi / Bonsoir selon l'heure. */
export function greeting(name: string): string {
  const h = new Date().getHours()
  const g = h < 5 ? 'Bonsoir' : h < 12 ? 'Bonjour' : h < 18 ? 'Bon après-midi' : 'Bonsoir'
  return `${g}, ${name}`
}

export function relativeTime(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const now = new Date()
  const sameDay = d.toDateString() === now.toDateString()
  if (sameDay) return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  const diff = (now.getTime() - d.getTime()) / 86400000
  if (diff < 7) return d.toLocaleDateString('fr-FR', { weekday: 'short' }).replace(/^\w/, (c) => c.toUpperCase())
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

export function groupByDate<T extends { last_message_at: string | null }>(items: T[]): { label: string; items: T[] }[] {
  const now = new Date()
  const today = now.toDateString()
  const groups: Record<string, T[]> = { "Aujourd'hui": [], 'Cette semaine': [], 'Plus ancien': [] }
  for (const it of items) {
    const d = it.last_message_at ? new Date(it.last_message_at) : now
    const days = (now.getTime() - d.getTime()) / 86400000
    if (d.toDateString() === today) groups["Aujourd'hui"].push(it)
    else if (days < 7) groups['Cette semaine'].push(it)
    else groups['Plus ancien'].push(it)
  }
  return Object.entries(groups).filter(([, v]) => v.length).map(([label, items]) => ({ label, items }))
}

export function formatTokens(n: number): string {
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace('.', ',') + ' M'
  if (n >= 1e3) return Math.round(n / 1e3) + ' k'
  return String(n)
}
