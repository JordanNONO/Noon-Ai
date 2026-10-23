import { BookOpen, Check, Copy, FileText, Languages, Loader2, RefreshCw, ThumbsDown, ThumbsUp, Volume2, VolumeX } from 'lucide-react'
import { useState } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Message, PendingAction } from '../lib/types'
import { splitLive } from '../lib/useChat'
import { canSpeak, speak, stopSpeaking } from '../lib/voice'
import { Button, Tile, useToast } from './ui'

export function Markdown({ text }: { text: string }) {
  return (
    <div className="prose-noon">
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={{ a: ({ href, children }) => <a href={href} target="_blank" rel="noreferrer">{children}</a> }}>
        {text}
      </ReactMarkdown>
    </div>
  )
}

function StepCard({ s }: { s: Message['steps'][number] }) {
  const tone = s.state === 'done' ? 'text-success' : s.state === 'error' ? 'text-danger' : 'font-semibold text-ochre'
  return (
    <div className="flex items-center gap-2.5 rounded-[10px] border border-line bg-surface px-3 py-2 text-[13.5px]">
      <Tile letter={s.letter} hue={s.hue} size={24} />
      <span className="min-w-0 flex-1 text-ink-2">{s.label}</span>
      <span className={`flex flex-none items-center gap-1 text-[12px] ${tone}`}>
        {s.state === 'running' && <Loader2 size={12} className="anim-spin" />}
        {s.status}
      </span>
    </div>
  )
}

function PendingCard({ action, onResolved }: { action: PendingAction; onResolved: (a: PendingAction, extra?: { message?: Message; resume_run_id?: number }) => void }) {
  const [busy, setBusy] = useState(false)
  const toast = useToast()
  if (action.status !== 'pending') return null
  const act = async (verb: 'approve' | 'reject') => {
    setBusy(true)
    try {
      const res = await api.post<{ action: PendingAction; message?: Message; resume_run_id?: number }>(`/pending-actions/${action.id}/${verb}`)
      onResolved(res.action, res)
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }
  const payload = action.payload ?? {}
  const preview = (payload.body ?? payload.text ?? payload.content ?? payload.description) as string | undefined
  return (
    <div className="flex flex-col gap-2.5 rounded-[14px] border border-ochre-bright/60 bg-ochre-50/60 p-3.5">
      <span className="text-[14px] font-semibold">{action.summary}</span>
      {preview && <p className="m-0 line-clamp-4 whitespace-pre-line text-[13.5px] text-ink-2">{preview}</p>}
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" disabled={busy} onClick={() => act('approve')}>{action.kind === 'combo_gate' ? 'Valider et continuer' : 'Confirmer'}</Button>
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => act('reject')}>Annuler</Button>
        <span className="text-[12.5px] text-muted">Rien n'est envoyé sans votre accord.</span>
      </div>
    </div>
  )
}

interface Props {
  m: Message
  onOpenDocument?: (doc: NonNullable<Message['document']>) => void
  onRegenerate?: (id: number) => void
  onResolved?: (a: PendingAction, extra?: { message?: Message; resume_run_id?: number }) => void
  last?: boolean
}

export default function MessageView({ m, onOpenDocument, onRegenerate, onResolved, last }: Props) {
  const { boot, user } = useApp()
  const toast = useToast()
  const [speaking, setSpeaking] = useState<'main' | 'fr' | null>(null)
  const [showFr, setShowFr] = useState(true)
  const [feedback, setFeedback] = useState(m.feedback)

  if (m.role === 'user') {
    return (
      <div className="flex justify-end">
        <div className="flex max-w-[85%] flex-col items-end gap-1.5">
          <div className="whitespace-pre-wrap rounded-[16px] bg-sunken px-4 py-3 text-[15.5px] leading-relaxed">{m.content}</div>
          {m.attachments.length > 0 && (
            <div className="flex flex-wrap justify-end gap-1">
              {m.attachments.map((a) => <span key={a.id} className="inline-flex items-center gap-1 rounded-[8px] bg-surface px-2 py-1 text-[12px] text-muted"><FileText size={12} />{a.name}</span>)}
            </div>
          )}
          {m.audio_url && <span className="text-[12px] text-muted">Message vocal</span>}
        </div>
      </div>
    )
  }

  const [liveMain, liveFr] = m.status === 'streaming' ? splitLive(m.content ?? '') : [m.content ?? '', m.translation]
  const lang = boot?.languages.find((l) => l.code === (m.language ?? user?.language ?? 'fr'))
  const fr = boot?.languages.find((l) => l.code === 'fr')
  const rate = user?.voice.rate ?? 2

  const listen = async (which: 'main' | 'fr') => {
    if (speaking) {
      stopSpeaking()
      setSpeaking(null)
      return
    }
    const target = which === 'fr' ? fr : lang
    if (!canSpeak(target)) {
      toast(`La voix en ${target?.name ?? 'cette langue'} n'est pas encore disponible.`, 'error')
      return
    }
    setSpeaking(which)
    try {
      await speak(which === 'fr' ? liveFr ?? '' : liveMain, target, rate, () => setSpeaking(null))
    } catch (e) {
      setSpeaking(null)
      toast((e as Error).message, 'error')
    }
  }

  const sendFeedback = async (f: 'up' | 'down') => {
    const next = feedback === f ? null : f
    setFeedback(next)
    api.post(`/messages/${m.id}/feedback`, { feedback: next }).catch(() => {})
  }

  const thinking = m.status === 'streaming' && !liveMain && m.steps.length === 0

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2 text-[13px] text-muted">
        {m.agent && <Tile letter={m.agent.initial} hue={m.agent.hue} size={22} />}
        <span className="font-medium text-ink-2">{m.agent?.name ?? 'Noon'}</span>
        {m.notice && <span className="text-[12px]">· {m.notice}</span>}
      </div>

      {m.steps.length > 0 && <div className="flex flex-col gap-1.5">{m.steps.map((s) => <StepCard key={s.id} s={s} />)}</div>}

      {thinking && (
        <div className="flex items-center gap-1.5 py-1" role="status" aria-label="Noon réfléchit">
          {[0, 1, 2].map((i) => <span key={i} className="anim-dot h-2 w-2 rounded-full bg-indigo/60" style={{ animationDelay: `${i * 0.15}s` }} />)}
        </div>
      )}

      {liveMain && (
        <div className={m.status === 'error' ? 'rounded-[12px] border border-danger/30 bg-danger-50 px-4 py-3 text-[15px] text-danger' : 'text-[15.5px]'}>
          {m.status === 'error' ? liveMain : <Markdown text={liveMain} />}
        </div>
      )}

      {liveFr && (
        <div className="rounded-[12px] border border-line bg-surface">
          <button type="button" onClick={() => setShowFr((v) => !v)} aria-expanded={showFr} className="flex w-full items-center gap-2 px-3.5 py-2.5 text-left text-[13px] font-semibold text-ink-2">
            <Languages size={15} /> Traduction en français
            <span className="ml-auto text-[12px] font-medium text-muted">{showFr ? 'Masquer' : 'Afficher'}</span>
          </button>
          {showFr && <div className="border-t border-line px-3.5 py-3 text-[15px]"><Markdown text={liveFr} /></div>}
        </div>
      )}

      {m.document && (
        <button type="button" onClick={() => onOpenDocument?.(m.document!)} className="flex items-center gap-3 self-start rounded-[12px] border border-line-strong bg-surface px-4 py-3 text-left hover:bg-sunken">
          <span className="flex h-9 w-9 items-center justify-center rounded-[8px] bg-sunken text-ink-2"><FileText size={18} /></span>
          <span className="flex flex-col">
            <span className="text-[14px] font-semibold">{m.document.title}</span>
            <span className="text-[12.5px] text-muted">Document · Toucher pour ouvrir</span>
          </span>
        </button>
      )}

      {m.sources.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 text-[12.5px]">
          <span className="inline-flex items-center gap-1 text-muted"><BookOpen size={13} /> Sources :</span>
          {m.sources.map((s) => <a key={s.id} href={`/savoirs?id=${s.id}`} className="rounded-full bg-ochre-50 px-2.5 py-1 text-ochre no-underline hover:underline">{s.title}</a>)}
        </div>
      )}

      {m.pending_actions.map((a) => <PendingCard key={a.id} action={a} onResolved={(x, extra) => onResolved?.(x, extra)} />)}

      {m.status !== 'streaming' && m.status !== 'error' && liveMain && (
        <div className="flex flex-wrap items-center gap-1 text-muted">
          <button type="button" onClick={() => listen('main')} className={`inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold ${speaking === 'main' ? 'bg-indigo text-surface' : 'bg-indigo-50 text-indigo hover:bg-indigo/15'}`}>
            {speaking === 'main' ? <VolumeX size={15} /> : <Volume2 size={15} />} {speaking === 'main' ? 'Arrêter' : 'Écouter'}
          </button>
          {liveFr && (
            <button type="button" onClick={() => listen('fr')} className="inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-[13px] font-medium hover:bg-sunken">
              <Volume2 size={15} /> {speaking === 'fr' ? 'Arrêter' : 'Écouter en français'}
            </button>
          )}
          <button type="button" aria-label="Copier" title="Copier" onClick={() => { navigator.clipboard?.writeText(liveFr ? `${liveMain}\n\n${liveFr}` : liveMain); toast('Copié') }} className="flex h-9 w-9 items-center justify-center rounded-[8px] hover:bg-sunken"><Copy size={16} /></button>
          <button type="button" aria-label="Bonne réponse" aria-pressed={feedback === 'up'} onClick={() => sendFeedback('up')} className={`flex h-9 w-9 items-center justify-center rounded-[8px] hover:bg-sunken ${feedback === 'up' ? 'text-success' : ''}`}>{feedback === 'up' ? <Check size={16} /> : <ThumbsUp size={16} />}</button>
          <button type="button" aria-label="Mauvaise réponse" aria-pressed={feedback === 'down'} onClick={() => sendFeedback('down')} className={`flex h-9 w-9 items-center justify-center rounded-[8px] hover:bg-sunken ${feedback === 'down' ? 'text-danger' : ''}`}><ThumbsDown size={16} /></button>
          {last && onRegenerate && <button type="button" aria-label="Refaire la réponse" title="Refaire la réponse" onClick={() => onRegenerate(m.id)} className="flex h-9 w-9 items-center justify-center rounded-[8px] hover:bg-sunken"><RefreshCw size={16} /></button>}
          {m.model && <span className="ml-auto text-[12px]">{m.model}</span>}
        </div>
      )}
    </div>
  )
}
