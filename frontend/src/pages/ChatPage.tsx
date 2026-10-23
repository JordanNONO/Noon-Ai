import { ChevronDown, Crown, FileText, Mic, PanelRight, Pin, PinOff, Play, Trash2, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import Composer, { type ComposerState } from '../components/Composer'
import MessageView, { Markdown } from '../components/MessageView'
import { Button, IconButton, LogoMark, NdopBand, Spinner, Tile, useToast } from '../components/ui'
import { api } from '../lib/api'
import { greeting, relativeTime, useApp } from '../lib/app'
import type { Agent, ConversationSummary, Message } from '../lib/types'
import { useAgents } from '../lib/useAgents'
import { useChat } from '../lib/useChat'

interface ConvDetail {
  conversation: ConversationSummary & { connector_ids: number[] | null; ai_model_id: number | null; combo_id: number | null }
  agent: Agent | null
  messages: Message[]
}

export default function ChatPage() {
  const { id } = useParams()
  const convId = id ? Number(id) : null
  const [search, setSearch] = useSearchParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { user, refresh } = useApp()
  const agents = useAgents()
  const createdRef = useRef<number | null>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  const [conv, setConv] = useState<ConvDetail['conversation'] | null>(null)
  const [loading, setLoading] = useState(false)
  const [doc, setDoc] = useState<Message['document']>(null)
  const [titleMenu, setTitleMenu] = useState(false)
  const [state, setState] = useState<ComposerState>({ agentId: null, modelId: null, connectorIds: null, language: user?.language ?? 'fr' })

  const chat = useChat({
    conversationId: convId,
    onConversation: (c) => {
      createdRef.current = c.id
      setConv({ ...c, connector_ids: state.connectorIds, ai_model_id: state.modelId, combo_id: null })
      navigate(`/c/${c.id}`, { replace: true })
    },
    onDone: (m) => {
      refresh().catch(() => {})
      if (m.document) setDoc(m.document)
    },
  })

  // Chargement d'une conversation existante (sauf celle qu'on vient de créer en direct)
  useEffect(() => {
    chat.setConversationId(convId)
    if (!convId) {
      setConv(null)
      chat.setMessages([])
      setDoc(null)
      const agentParam = search.get('assistant')
      setState((s) => ({ ...s, agentId: agentParam ? Number(agentParam) : null, language: user?.language ?? 'fr' }))
      return
    }
    if (createdRef.current === convId) return
    setLoading(true)
    api.get<ConvDetail>(`/conversations/${convId}`)
      .then((d) => {
        setConv(d.conversation)
        chat.setMessages(d.messages)
        setState((s) => ({
          ...s,
          agentId: d.agent && !d.agent.is_default ? d.agent.id : null,
          modelId: d.conversation.ai_model_id,
          connectorIds: d.conversation.connector_ids,
          language: d.conversation.language ?? user?.language ?? 'fr',
        }))
        setDoc([...d.messages].reverse().find((m) => m.document)?.document ?? null)
      })
      .catch(() => {
        toast('Conversation introuvable.', 'error')
        navigate('/', { replace: true })
      })
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [convId])

  // Lancement d'un parcours depuis la page Parcours
  useEffect(() => {
    const combo = search.get('parcours')
    if (combo && !convId) {
      setSearch({}, { replace: true })
      chat.runCombo(Number(combo))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [chat.messages])

  useEffect(() => {
    if (chat.error) toast(chat.error, 'error')
  }, [chat.error, toast])

  const send = (text: string, opts: Parameters<typeof chat.send>[1]) => chat.send(text, opts)

  const togglePin = async () => {
    if (!conv) return
    const res = await api.patch<{ conversation: ConversationSummary }>(`/conversations/${conv.id}`, { is_pinned: !conv.is_pinned })
    setConv({ ...conv, ...res.conversation })
    setTitleMenu(false)
    refresh()
  }
  const rename = async () => {
    if (!conv) return
    const t = window.prompt('Nouveau titre', conv.title)
    if (!t?.trim()) return
    const res = await api.patch<{ conversation: ConversationSummary }>(`/conversations/${conv.id}`, { title: t.trim() })
    setConv({ ...conv, ...res.conversation })
    setTitleMenu(false)
    refresh()
  }
  const remove = async () => {
    if (!conv || !window.confirm('Supprimer cette conversation ?')) return
    await api.del(`/conversations/${conv.id}`)
    await refresh()
    navigate('/', { replace: true })
  }

  const empty = !convId && chat.messages.length === 0 && !chat.busy
  const lastAssistant = [...chat.messages].reverse().find((m) => m.role === 'assistant')

  if (empty) return <Home onSend={send} state={state} setState={setState} agents={agents} busy={chat.busy} />

  return (
    <div className="flex min-h-0 flex-1">
      <section className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between gap-2 px-3 py-2 sm:px-5">
          <div className="relative min-w-0">
            <button type="button" onClick={() => setTitleMenu((v) => !v)} aria-expanded={titleMenu} className="flex h-10 max-w-full items-center gap-2 rounded-[10px] px-2.5 text-[14.5px] font-semibold hover:bg-sunken">
              <span className="truncate">{conv?.title ?? 'Nouvelle conversation'}</span>
              <ChevronDown size={15} className="flex-none text-muted" />
            </button>
            {titleMenu && conv && (
              <div className="absolute left-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-[12px] border border-line bg-surface shadow-lg">
                <button type="button" onClick={rename} className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[14px] hover:bg-sunken">Renommer</button>
                <button type="button" onClick={togglePin} className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[14px] hover:bg-sunken">{conv.is_pinned ? <PinOff size={15} /> : <Pin size={15} />}{conv.is_pinned ? 'Désépingler' : 'Épingler'}</button>
                <button type="button" onClick={remove} className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[14px] text-danger hover:bg-danger-50"><Trash2 size={15} />Supprimer</button>
              </div>
            )}
          </div>
          <div className="flex items-center gap-1">
            <Link to={`/vocal${conv ? `?c=${conv.id}` : ''}`} aria-label="Continuer à la voix" title="Continuer à la voix" className="flex h-10 w-10 items-center justify-center rounded-[10px] text-indigo hover:bg-indigo-50"><Mic size={18} /></Link>
            {doc && <IconButton label="Afficher ou masquer le document" onClick={() => setDoc((d) => (d ? null : d))}><PanelRight size={18} /></IconButton>}
          </div>
        </header>

        <div className="flex-1 overflow-y-auto">
          <div className="mx-auto flex w-full max-w-[760px] flex-col gap-7 px-4 py-5 sm:px-6">
            {loading && <Spinner />}
            {chat.messages.map((m) => (
              <MessageView
                key={m.id}
                m={m}
                last={m.id === lastAssistant?.id && !chat.busy}
                onOpenDocument={setDoc}
                onRegenerate={(mid) => chat.regenerate(mid)}
                onResolved={(a, extra) => {
                  chat.setMessages((l) => {
                    const next = l.map((x) => ({ ...x, pending_actions: x.pending_actions.map((p) => (p.id === a.id ? a : p)) }))
                    return extra?.message ? [...next, extra.message] : next
                  })
                  if (extra?.resume_run_id) chat.resumeCombo(extra.resume_run_id)
                }}
              />
            ))}
            <div ref={bottomRef} />
          </div>
        </div>

        <div className="mx-auto w-full max-w-[760px] px-3 pb-4 sm:px-6">
          <QuotaHint />
          <Composer onSend={send} busy={chat.busy} onStop={chat.stop} agents={agents} state={state} onState={setState} placeholder="Répondre… (tapez @ pour choisir un assistant)" />
          <p className="m-0 mt-2 text-center text-[12px] text-muted">Noon peut se tromper, surtout en langue bamiléké. Vérifiez les informations importantes.</p>
        </div>
      </section>

      {doc && (
        <aside aria-label="Document" className="fixed inset-0 z-40 flex flex-col bg-surface lg:static lg:z-auto lg:w-[42%] lg:max-w-[560px] lg:border-l lg:border-line">
          <div className="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
            <span className="flex min-w-0 items-center gap-2 text-[14.5px] font-semibold"><FileText size={16} /><span className="truncate">{doc.title}</span></span>
            <div className="flex gap-1.5">
              <Button size="sm" variant="secondary" onClick={() => { navigator.clipboard?.writeText(doc.body); toast('Document copié') }}>Copier</Button>
              <IconButton label="Fermer le document" onClick={() => setDoc(null)}><X size={18} /></IconButton>
            </div>
          </div>
          <article className="flex-1 overflow-y-auto px-6 py-6 sm:px-8">
            <h2 className="m-0 mb-4 font-display text-[24px] font-semibold leading-tight">{doc.title}</h2>
            <Markdown text={doc.body} />
          </article>
        </aside>
      )}
    </div>
  )
}

function QuotaHint() {
  const { boot } = useApp()
  const q = boot?.quota
  if (!q || q.remaining_today === null || q.remaining_today > 5) return null
  return (
    <div className="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-[12px] border border-ochre-bright/50 bg-ochre-50 px-3.5 py-2.5 text-[13.5px]">
      <span>{q.remaining_today === 0 ? 'Vous avez utilisé tous vos messages gratuits du jour.' : `Plus que ${q.remaining_today} message${q.remaining_today > 1 ? 's' : ''} gratuit${q.remaining_today > 1 ? 's' : ''} aujourd'hui.`}</span>
      <Link to="/offres" className="inline-flex items-center gap-1 font-semibold text-ochre no-underline hover:underline"><Crown size={14} /> Voir les offres</Link>
    </div>
  )
}

function Home({ onSend, state, setState, agents, busy }: { onSend: (t: string, o: Parameters<ReturnType<typeof useChat>['send']>[1]) => void; state: ComposerState; setState: (s: ComposerState) => void; agents: Agent[]; busy: boolean }) {
  const { boot, user } = useApp()
  const navigate = useNavigate()
  if (!boot || !user) return <Spinner />
  const c = boot.counts
  const selected = agents.find((a) => a.id === state.agentId) ?? boot.default_agent
  const starters = selected.starters.length ? selected.starters : boot.default_agent.starters
  const recents = boot.recent.slice(0, 3)
  const opts = { agent_id: state.agentId, model_id: state.modelId, connector_ids: state.connectorIds, language: state.language }

  return (
    <div className="flex flex-1 flex-col">
      <NdopBand className="block opacity-90" />
      <div className="mx-auto flex w-full max-w-[820px] flex-1 flex-col gap-7 px-4 py-8 sm:px-6 sm:py-12">
        <div className="flex flex-col items-center gap-3 text-center">
          <LogoMark size={48} />
          <h1 className="m-0 font-display text-[30px] font-medium leading-tight sm:text-[38px]">{greeting(user.first_name)}</h1>
          <p className="m-0 text-[15.5px] text-muted">
            Noon parle {user.language_name.toLowerCase() === 'français' ? 'français' : `${user.language_name} et français`}. {c.agents} assistants{c.connectors_connected ? ` et ${c.connectors_connected} connecteur${c.connectors_connected > 1 ? 's' : ''}` : ''} sont prêts.
          </p>
        </div>

        <Link to="/vocal" className="group mx-auto flex items-center gap-3 rounded-full bg-indigo py-2 pl-2 pr-6 text-surface no-underline shadow-md hover:bg-indigo-700">
          <span className="flex h-12 w-12 items-center justify-center rounded-full bg-ochre-bright text-ink"><Mic size={22} /></span>
          <span className="flex flex-col text-left">
            <span className="text-[16px] font-semibold">Parler à Noon</span>
            <span className="text-[12.5px] text-surface/80">Touchez et parlez, Noon vous répond à voix haute</span>
          </span>
        </Link>

        <Composer onSend={onSend} busy={busy} agents={agents} state={state} onState={setState} big autoFocus placeholder="Écrivez votre question ici… ou tapez @ pour choisir un assistant" />
        <QuotaHint />

        <div className="flex flex-wrap justify-center gap-2">
          {starters.map((s) => (
            <button key={s} type="button" onClick={() => onSend(s, opts)} className="rounded-full border border-line bg-surface px-4 py-2 text-[14px] text-ink-2 hover:border-indigo hover:text-indigo">{s}</button>
          ))}
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <section className="flex flex-col gap-2 rounded-[16px] border border-line bg-surface p-4">
            <div className="flex items-baseline justify-between"><h2 className="m-0 text-[15px] font-semibold">Reprendre</h2><Link to="/discussions" className="text-[13px] text-indigo">Tout voir</Link></div>
            {recents.length === 0 && <p className="m-0 py-3 text-[14px] text-muted">Vos conversations apparaîtront ici.</p>}
            {recents.map((r) => (
              <Link key={r.id} to={`/c/${r.id}`} className="flex items-center gap-3 rounded-[10px] px-1.5 py-2 no-underline hover:bg-sunken">
                <Tile letter={r.agent?.initial ?? 'N'} hue={r.agent?.hue ?? 'indigo'} size={34} />
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate text-[14px] font-semibold text-ink">{r.title}</span>
                  <span className="truncate text-[12.5px] text-muted">{r.snippet}</span>
                </span>
                <span className="text-[12px] text-muted">{relativeTime(r.last_message_at)}</span>
              </Link>
            ))}
          </section>
          <section className="flex flex-col gap-2 rounded-[16px] border border-line bg-surface p-4">
            <div className="flex items-baseline justify-between"><h2 className="m-0 text-[15px] font-semibold">Parcours favoris</h2><Link to="/parcours" className="text-[13px] text-indigo">Tous les parcours</Link></div>
            {boot.favorite_combos.length === 0 && <p className="m-0 py-3 text-[14px] text-muted">Aucun parcours favori.</p>}
            {boot.favorite_combos.map((cb) => (
              <div key={cb.id} className="flex items-center gap-3 px-1.5 py-2">
                <span className="flex">
                  {cb.steps.map((s, i) => s.agent && <span key={i} className={i ? '-ml-2' : ''}><Tile letter={s.agent.initial} hue={s.agent.hue} size={30} round className="ring-2 ring-surface" /></span>)}
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate text-[14px] font-semibold">{cb.name}</span>
                  <span className="truncate text-[12.5px] text-muted">{cb.meta}</span>
                </span>
                <button type="button" onClick={() => navigate(`/?parcours=${cb.id}`)} className="inline-flex h-9 items-center gap-1.5 rounded-[9px] border border-line-strong px-3 text-[13px] font-semibold hover:bg-sunken"><Play size={11} fill="currentColor" />Lancer</button>
              </div>
            ))}
          </section>
        </div>
      </div>
    </div>
  )
}
