import { useEffect, useState } from 'react'
import { api } from './api'
import type { Agent } from './types'

let cache: Agent[] | null = null
const listeners = new Set<(a: Agent[]) => void>()

export async function reloadAgents(): Promise<Agent[]> {
  const res = await api.get<{ agents: Agent[] }>('/agents')
  cache = res.agents
  listeners.forEach((l) => l(res.agents))
  return res.agents
}

/** Liste des assistants accessibles, partagée entre les écrans. */
export function useAgents(): Agent[] {
  const [agents, setAgents] = useState<Agent[]>(cache ?? [])
  useEffect(() => {
    listeners.add(setAgents)
    if (!cache) reloadAgents().catch(() => {})
    return () => {
      listeners.delete(setAgents)
    }
  }, [])
  return agents
}
