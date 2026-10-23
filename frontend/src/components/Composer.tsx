import { ArrowUp, FileText, Image as ImageIcon, Loader2, Mic, Paperclip, Plus, Square, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Agent } from '../lib/types'
import { canListen, listen, type ListenSession } from '../lib/voice'
import type { SendOptions } from '../lib/useChat'
import { Tile, useToast } from './ui'

export interface ComposerState {
  agentId: number | null
  modelId: number | null
  connectorIds: number[] | null
  language: string
}

interface Props {
  onSend: (text: string, opts: SendOptions) => void
  busy?: boolean
  onStop?: () => void
  agents?: Agent[]
  state: ComposerState
  onState: (s: ComposerState) => void
  big?: boolean
  placeholder?: string
  autoFocus?: boolean
}

interface Attached { id: number; name: string; mime: string; uploading?: boolean }

export default function Composer({ onSend, busy, onStop, agents = [], state, onState, big, placeholder, autoFocus }: Props) {
  const { boot } = useApp()
  const toast = useToast()
  const [text, setText] = useState('')
  const [menu, setMenu] = useState(false)
  const [files, setFiles] = useState<Attached[]>([])
  const [rec, setRec] = useState<ListenSession | null>(null)
  const [audioToken, setAudioToken] = useState<string | undefined>()
  const ta = useRef<HTMLTextAreaElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const languages = boot?.languages ?? []
  const lang = languages.find((l) => l.code === state.language)
  const connectors = boot?.connectors ?? []
  const activeConnectors = state.connectorIds === null ? connectors.map((c) => c.id) : state.connectorIds
  const agent = agents.find((a) => a.id === state.agentId)

  // Mention « @assistant » : propose les assistants dont le nom commence par ce qui est tapé
  const mention = useMemo(() => {
    const m = /(^|\s)@([\p{L}' -]*)$/u.exec(text)
    if (!m) return null
    const q = m[2].toLowerCase()
    return agents.filter((a) => a.name.toLowerCase().startsWith(q)).slice(0, 6)
  }, [text, agents])

  useEffect(() => {
    const el = ta.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 240) + 'px'
  }, [text])

  const submit = () => {
    const t = text.trim()
    if (!t || busy || files.some((f) => f.uploading)) return
    onSend(t, {
      agent_id: state.agentId,
      model_id: state.modelId,
      connector_ids: state.connectorIds,
      attachment_ids: files.map((f) => f.id),
      language: state.language,
      audio_token: audioToken,
    })
    setText('')
    setFiles([])
    setAudioToken(undefined)
    setMenu(false)
  }

  const upload = async (list: FileList | null) => {
    if (!list) return
    for (const f of Array.from(list)) {
      const tmpId = -Date.now() - Math.random()
      setFiles((l) => [...l, { id: tmpId, name: f.name, mime: f.type, uploading: true }])
      const form = new FormData()
      form.append('file', f)
      try {
        const res = await api.upload<{ attachment: Attached }>('/attachments', form)
        setFiles((l) => l.map((x) => (x.id === tmpId ? res.attachment : x)))
      } catch (e) {
        setFiles((l) => l.filter((x) => x.id !== tmpId))
        toast((e as Error).message, 'error')
      }
    }
    setMenu(false)
  }

  const toggleMic = async () => {
    if (rec) {
      rec.stop()
      return
    }
    if (!lang || !canListen(lang)) {
      toast(`La dictée en ${lang?.name ?? 'cette langue'} n'est pas encore disponible. Vous pouvez écrire, ou choisir le français.`, 'error')
      return
    }
    const base = text ? text.trimEnd() + ' ' : ''
    const session = listen(lang, (partial) => setText(base + partial))
    setRec(session)
    try {
      const r = await session.result
      if (r.text) setText(base + r.text)
      if (r.audioToken) setAudioToken(r.audioToken)
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setRec(null)
      ta.current?.focus()
    }
  }

  const toggleConnector = (id: number) => {
    const next = activeConnectors.includes(id) ? activeConnectors.filter((x) => x !== id) : [...activeConnectors, id]
    onState({ ...state, connectorIds: next })
  }

  const chip = 'inline-flex h-9 items-center gap-1.5 rounded-full border border-line bg-surface px-3 text-[13px] font-medium text-ink-2 hover:bg-sunken'

  return (
    <div className="relative">
      <div className={`flex flex-col gap-2 rounded-[20px] border border-line-strong bg-surface shadow-[0_2px_12px_rgba(22,27,46,.06)] ${big ? 'p-4' : 'px-4 pb-2.5 pt-3'}`}>
        {(agent && !agent.is_default) && (
          <div className="flex">
            <span className="inline-flex items-center gap-2 rounded-full bg-indigo-50 py-1 pl-1 pr-2 text-[13px] font-semibold text-indigo">
              <Tile letter={agent.initial} hue={agent.hue} size={22} round /> {agent.name}
              <button type="button" aria-label="Revenir à Noon" onClick={() => onState({ ...state, agentId: null })} className="flex h-5 w-5 items-center justify-center rounded-full hover:bg-indigo/10"><X size={13} /></button>
            </span>
          </div>
        )}

        {files.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {files.map((f) => (
              <span key={f.id} className="inline-flex max-w-[220px] items-center gap-1.5 rounded-[8px] bg-sunken py-1 pl-2 pr-1 text-[12.5px]">
                {f.uploading ? <Loader2 size={13} className="anim-spin" /> : f.mime.startsWith('image/') ? <ImageIcon size={13} /> : <FileText size={13} />}
                <span className="truncate">{f.name}</span>
                <button type="button" aria-label={`Retirer ${f.name}`} onClick={() => setFiles((l) => l.filter((x) => x.id !== f.id))} className="flex h-5 w-5 items-center justify-center rounded hover:bg-line"><X size={12} /></button>
              </span>
            ))}
          </div>
        )}

        <label className="flex">
          <span className="sr-only-noon">Votre demande</span>
          <textarea
            ref={ta}
            rows={big ? 3 : 1}
            value={text}
            autoFocus={autoFocus}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault()
                submit()
              }
            }}
            placeholder={rec ? 'Je vous écoute…' : placeholder ?? 'Demandez ce que vous voulez, ou touchez le micro pour parler…'}
            className={`min-h-[28px] flex-1 resize-none border-0 bg-transparent leading-normal text-ink outline-none ${big ? 'text-[17px]' : 'text-[15.5px]'}`}
          />
        </label>

        <div className="flex flex-wrap items-center gap-1.5">
          <button type="button" onClick={() => setMenu((v) => !v)} aria-expanded={menu} aria-label="Ajouter des fichiers et des connecteurs" className={`flex h-9 w-9 items-center justify-center rounded-[10px] border ${menu ? 'border-indigo bg-indigo-50 text-indigo' : 'border-line text-ink-2'}`}>
            <Plus size={17} />
          </button>
          {connectors.length > 0 && (
            <button type="button" onClick={() => setMenu(true)} className={chip}>
              {activeConnectors.length} connecteur{activeConnectors.length > 1 ? 's' : ''}
            </button>
          )}
          <label className={chip + ' pr-1'}>
            <span className="sr-only-noon">Langue de la réponse</span>
            <select value={state.language} onChange={(e) => onState({ ...state, language: e.target.value })} className="h-8 max-w-[150px] cursor-pointer border-0 bg-transparent text-[13px] font-medium outline-none">
              {languages.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}
            </select>
          </label>
          <span className="flex-1" />
          {(boot?.models.length ?? 0) > 0 && (
            <label className="hidden sm:flex">
              <span className="sr-only-noon">Modèle</span>
              <select
                value={state.modelId ?? ''}
                onChange={(e) => onState({ ...state, modelId: e.target.value ? Number(e.target.value) : null })}
                className="h-9 max-w-[220px] rounded-[10px] border border-line bg-surface px-2 text-[13px] text-ink-2 outline-none"
              >
                <option value="">Automatique</option>
                {boot!.models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
              </select>
            </label>
          )}
          <button
            type="button"
            onClick={toggleMic}
            aria-pressed={!!rec}
            aria-label={rec ? 'Arrêter la dictée' : `Dicter en ${lang?.name ?? 'français'}`}
            title={rec ? 'Arrêter' : `Dicter en ${lang?.name ?? 'français'}`}
            className={`flex h-10 w-10 items-center justify-center rounded-full ${rec ? 'anim-pulse bg-ochre-bright text-ink' : 'border border-line-strong bg-surface text-indigo hover:bg-indigo-50'}`}
          >
            {rec ? <Square size={15} fill="currentColor" /> : <Mic size={18} />}
          </button>
          {busy ? (
            <button type="button" onClick={onStop} aria-label="Arrêter la réponse" className="flex h-10 w-10 items-center justify-center rounded-[11px] bg-ink text-surface"><Square size={14} fill="currentColor" /></button>
          ) : (
            <button type="button" onClick={submit} disabled={!text.trim()} aria-label="Envoyer" className="flex h-10 w-10 items-center justify-center rounded-[11px] bg-indigo text-surface disabled:opacity-40"><ArrowUp size={18} strokeWidth={2.4} /></button>
          )}
        </div>
      </div>

      {mention && mention.length > 0 && (
        <div className="absolute bottom-full left-3 z-20 mb-2 w-72 overflow-hidden rounded-[12px] border border-line bg-surface shadow-lg">
          {mention.map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => {
                onState({ ...state, agentId: a.is_default ? null : a.id })
                setText((t) => t.replace(/(^|\s)@[\p{L}' -]*$/u, '$1'))
                ta.current?.focus()
              }}
              className="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-sunken"
            >
              <Tile letter={a.initial} hue={a.hue} size={26} />
              <span className="flex min-w-0 flex-col">
                <span className="text-[14px] font-semibold">{a.name}</span>
                <span className="truncate text-[12px] text-muted">{a.description}</span>
              </span>
            </button>
          ))}
        </div>
      )}

      {menu && (
        <div className="anim-in mt-2 grid gap-3 rounded-[16px] border border-line bg-surface p-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <span className="px-1 text-[11.5px] font-semibold uppercase tracking-[.04em] text-muted">Ajouter</span>
            <button type="button" onClick={() => fileInput.current?.click()} className="flex min-h-[44px] items-center gap-2.5 rounded-[10px] px-2 text-left text-[14px] hover:bg-sunken">
              <Paperclip size={17} className="text-muted" /> Fichier ou photo
            </button>
            <input ref={fileInput} type="file" multiple hidden accept="image/*,.pdf,.doc,.docx,.txt,.md,.csv,.xlsx,.pptx" onChange={(e) => upload(e.target.files)} />
            <span className="px-2 text-[12.5px] text-muted">Photo d'un papier, d'une ordonnance, PDF, document Word…</span>
          </div>
          <div className="flex flex-col gap-1">
            <span className="px-1 text-[11.5px] font-semibold uppercase tracking-[.04em] text-muted">Connecteurs</span>
            {connectors.length === 0 && <span className="px-2 py-2 text-[13px] text-muted">Aucun connecteur relié pour l'instant.</span>}
            {connectors.map((c) => (
              <label key={c.id} className="flex min-h-[42px] cursor-pointer items-center gap-2.5 rounded-[10px] px-2 hover:bg-sunken">
                <Tile letter={c.letter} hue={c.hue} size={26} />
                <span className="flex-1 text-[14px]">{c.name}</span>
                <input type="checkbox" className="h-[18px] w-[18px] accent-indigo" checked={activeConnectors.includes(c.id)} onChange={() => toggleConnector(c.id)} />
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
