/** Client de l'API Noon : jeton, erreurs lisibles, réponses en flux (SSE). */

export const API_URL: string = (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://127.0.0.1:8000/api'

const TOKEN_KEY = 'noon.token'

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* stockage indisponible : la session durera le temps de l'onglet */
  }
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void): void {
  onUnauthorized = fn
}

function headers(json = true): Record<string, string> {
  const h: Record<string, string> = { Accept: 'application/json' }
  if (json) h['Content-Type'] = 'application/json'
  const t = getToken()
  if (t) h.Authorization = `Bearer ${t}`
  return h
}

async function handle<T>(res: Response): Promise<T> {
  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401) onUnauthorized?.()
    const errors = (data?.errors ?? {}) as Record<string, string[]>
    const first = Object.values(errors)[0]?.[0]
    throw new ApiError(res.status, first ?? data?.message ?? 'Une erreur est survenue.', errors)
  }
  return data as T
}

export const api = {
  get<T>(path: string): Promise<T> {
    return fetch(API_URL + path, { headers: headers(false) }).then((r) => handle<T>(r))
  },
  post<T>(path: string, body?: unknown): Promise<T> {
    return fetch(API_URL + path, { method: 'POST', headers: headers(), body: body === undefined ? undefined : JSON.stringify(body) }).then((r) => handle<T>(r))
  },
  put<T>(path: string, body?: unknown): Promise<T> {
    return fetch(API_URL + path, { method: 'PUT', headers: headers(), body: JSON.stringify(body ?? {}) }).then((r) => handle<T>(r))
  },
  patch<T>(path: string, body?: unknown): Promise<T> {
    return fetch(API_URL + path, { method: 'PATCH', headers: headers(), body: JSON.stringify(body ?? {}) }).then((r) => handle<T>(r))
  },
  del<T>(path: string): Promise<T> {
    return fetch(API_URL + path, { method: 'DELETE', headers: headers(false) }).then((r) => handle<T>(r))
  },
  upload<T>(path: string, form: FormData): Promise<T> {
    return fetch(API_URL + path, { method: 'POST', headers: headers(false), body: form }).then((r) => handle<T>(r))
  },
  async blob(path: string, body?: unknown): Promise<Blob> {
    const res = await fetch(API_URL + path, body === undefined ? { headers: headers(false) } : { method: 'POST', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) {
      const data = await res.json().catch(() => ({}))
      throw new ApiError(res.status, data?.message ?? 'Fichier indisponible.')
    }
    return res.blob()
  },
}

export type StreamEvent = { type: string; [k: string]: unknown }

/** POST qui renvoie un flux d'événements ; chaque événement est transmis à onEvent. */
export async function stream(path: string, body: unknown, onEvent: (e: StreamEvent) => void, signal?: AbortSignal): Promise<void> {
  const res = await fetch(API_URL + path, {
    method: 'POST',
    headers: { ...headers(), Accept: 'text/event-stream' },
    body: JSON.stringify(body ?? {}),
    signal,
  })
  if (!res.ok || !res.body) {
    await handle(res)
    return
  }
  const reader = res.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  for (;;) {
    const { value, done } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })
    let idx
    while ((idx = buffer.indexOf('\n\n')) !== -1) {
      const chunk = buffer.slice(0, idx).trim()
      buffer = buffer.slice(idx + 2)
      if (!chunk.startsWith('data:')) continue
      const data = chunk.slice(5).trim()
      if (data === '[DONE]') return
      try {
        onEvent(JSON.parse(data) as StreamEvent)
      } catch {
        /* morceau incomplet ignoré */
      }
    }
  }
}
