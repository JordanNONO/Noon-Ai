import { useCallback, useRef, useState } from 'react'
import { stream, type StreamEvent } from './api'
import type { AgentLite, ConversationSummary, Message, PendingAction, Step } from './types'

export interface SendOptions {
  agent_id?: number | null
  model_id?: number | null
  connector_ids?: number[] | null
  attachment_ids?: number[]
  language?: string | null
  audio_token?: string
}

const MARKER = '---FR---'

/** Sépare en direct la réponse en langue bamiléké de sa traduction française. */
export function splitLive(text: string): [string, string | null] {
  const i = text.indexOf(MARKER)
  if (i === -1) return [text, null]
  return [text.slice(0, i).trim(), text.slice(i + MARKER.length).trim()]
}

function blankAssistant(id: number, agent: AgentLite | null): Message {
  return {
    id, role: 'assistant', agent, content: '', translation: null, language: null, audio_url: null, steps: [], document: null,
    sources: [], attachments: [], model: null, tokens: 0, status: 'streaming', feedback: null, pending_actions: [], created_at: new Date().toISOString(),
  }
}

/**
 * Conversation en flux. `onConversation` est appelé quand le serveur crée la conversation
 * (premier message), `onDone` à la fin de chaque réponse.
 */
export function useChat(opts: { conversationId?: number | null; onConversation?: (c: ConversationSummary) => void; onDone?: (m: Message) => void } = {}) {
  const [messages, setMessages] = useState<Message[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const convRef = useRef<number | null>(opts.conversationId ?? null)
  const abortRef = useRef<AbortController | null>(null)
  const cb = useRef(opts)
  cb.current = opts

  const setConversationId = useCallback((id: number | null) => {
    convRef.current = id
  }, [])

  const patchLast = (fn: (m: Message) => Message) =>
    setMessages((list) => {
      const idx = list.findLastIndex((m) => m.role === 'assistant' && m.status === 'streaming')
      if (idx === -1) return list
      const copy = list.slice()
      copy[idx] = fn(copy[idx])
      return copy
    })

  const handle = useCallback((e: StreamEvent) => {
    switch (e.type) {
      case 'conversation': {
        const c = e.conversation as ConversationSummary
        convRef.current = c.id
        cb.current.onConversation?.(c)
        break
      }
      case 'user_message': {
        const m = e.message as Message
        setMessages((l) => [...l.filter((x) => !(x.role === 'user' && x.id < 0)), m])
        break
      }
      case 'start':
        setMessages((l) => [...l, blankAssistant(e.message_id as number, (e.agent as AgentLite) ?? null)])
        break
      case 'delta':
        patchLast((m) => ({ ...m, content: (m.content ?? '') + (e.text as string) }))
        break
      case 'step': {
        const s = e.step as Step
        patchLast((m) => {
          const i = m.steps.findIndex((x) => x.id === s.id)
          const steps = i === -1 ? [...m.steps, s] : m.steps.map((x) => (x.id === s.id ? s : x))
          return { ...m, steps }
        })
        break
      }
      case 'document':
        patchLast((m) => ({ ...m, document: e.document as Message['document'] }))
        break
      case 'pending':
        patchLast((m) => ({ ...m, pending_actions: [...m.pending_actions, e.action as PendingAction] }))
        break
      case 'notice':
        patchLast((m) => ({ ...m, notice: e.text as string }))
        break
      case 'done': {
        const final = e.message as Message
        setMessages((l) => l.map((m) => (m.id === final.id ? final : m)))
        cb.current.onDone?.(final)
        break
      }
      case 'error':
        setError(e.message as string)
        break
    }
  }, [])

  const run = useCallback(async (path: string, body: unknown, optimisticUser?: string) => {
    setError(null)
    setBusy(true)
    if (optimisticUser !== undefined) {
      setMessages((l) => [...l, { ...blankAssistant(-Date.now(), null), role: 'user', content: optimisticUser, status: 'done' }])
    }
    const ctrl = new AbortController()
    abortRef.current = ctrl
    try {
      await stream(path, body, handle, ctrl.signal)
    } catch (err) {
      if ((err as Error).name !== 'AbortError') setError((err as Error).message)
    } finally {
      setBusy(false)
      abortRef.current = null
      // Une réponse interrompue ne doit pas rester « en cours »
      setMessages((l) => l.map((m) => (m.status === 'streaming' ? { ...m, status: 'done' } : m)))
    }
  }, [handle])

  const send = useCallback((content: string, o: SendOptions = {}) => {
    const path = convRef.current ? `/conversations/${convRef.current}/messages` : '/conversations/messages'
    return run(path, { content, ...o }, content)
  }, [run])

  const regenerate = useCallback((messageId: number) => {
    setMessages((l) => {
      const idx = l.findIndex((m) => m.id === messageId)
      return idx > 0 ? l.slice(0, idx - 1) : l
    })
    return run(`/messages/${messageId}/regenerate`, {})
  }, [run])

  const runCombo = useCallback((comboId: number, input?: string) => run(`/combos/${comboId}/run`, { input }), [run])
  const resumeCombo = useCallback((runId: number) => run(`/combo-runs/${runId}/resume`, {}), [run])

  const stop = useCallback(() => abortRef.current?.abort(), [])

  return { messages, setMessages, busy, error, setError, send, regenerate, runCombo, resumeCombo, stop, setConversationId, conversationId: convRef }
}
