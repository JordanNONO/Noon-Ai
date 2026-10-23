import { ArrowLeft, MessageSquareText, Mic, MicOff, Square } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { LogoMark, Tile, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Message, PendingAction } from '../lib/types'
import { splitLive, useChat } from '../lib/useChat'
import { canListen, canSpeak, listen, speak, stopSpeaking, type ListenSession } from '../lib/voice'

type Phase = 'idle' | 'listen' | 'think' | 'speak'
const TITLES: Record<Phase, string> = { idle: 'Touchez pour parler', listen: "J'écoute", think: 'Je réfléchis', speak: 'Je réponds' }

export default function Voice() {
  const { boot, user, refresh } = useApp()
  const toast = useToast()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [phase, setPhase] = useState<Phase>('idle')
  const [heard, setHeard] = useState('')
  const [level, setLevel] = useState(0)
  const [convId, setConvId] = useState<number | null>(params.get('c') ? Number(params.get('c')) : null)
  const [langCode, setLangCode] = useState(user?.language ?? 'fr')
  const session = useRef<ListenSession | null>(null)

  const lang = boot?.languages.find((l) => l.code === langCode)
  const fr = boot?.languages.find((l) => l.code === 'fr')

  const chat = useChat({
    conversationId: convId,
    onConversation: (c) => setConvId(c.id),
    onDone: (m) => {
      refresh().catch(() => {})
      const [main] = [m.content ?? '']
      const target = canSpeak(lang) ? lang : fr
      if (user?.voice.auto_read && main && m.status !== 'error') {
        setPhase('speak')
        speak(target === lang ? main : m.translation ?? main, target, user.voice.rate, () => setPhase('idle')).catch(() => setPhase('idle'))
      } else {
        setPhase('idle')
      }
    },
  })

  useEffect(() => () => {
    session.current?.stop()
    stopSpeaking()
  }, [])
  useEffect(() => {
    if (chat.error) {
      toast(chat.error, 'error')
      setPhase('idle')
    }
  }, [chat.error, toast])

  const last = [...chat.messages].reverse().find((m) => m.role === 'assistant') as Message | undefined
  const [lastMain, lastFr] = last ? (last.status === 'streaming' ? splitLive(last.content ?? '') : [last.content ?? '', last.translation]) : ['', null]

  const start = async () => {
    if (phase === 'speak') {
      stopSpeaking()
    }
    if (phase === 'listen') {
      session.current?.stop()
      return
    }
    if (phase === 'think') return
    if (!lang || !canListen(lang)) {
      toast(`L'écoute en ${lang?.name ?? 'cette langue'} n'est pas encore disponible ici. Choisissez le français ou écrivez votre question.`, 'error')
      return
    }
    setHeard('')
    setPhase('listen')
    const s = listen(lang, setHeard, setLevel)
    session.current = s
    try {
      const r = await s.result
      session.current = null
      if (!r.text) {
        setPhase('idle')
        return
      }
      setHeard(r.text)
      setPhase('think')
      await chat.send(r.text, { language: langCode, audio_token: r.audioToken })
    } catch (e) {
      session.current = null
      toast((e as Error).message, 'error')
      setPhase('idle')
    }
  }

  const resolve = async (a: PendingAction, verb: 'approve' | 'reject') => {
    try {
      const res = await api.post<{ action: PendingAction; message?: Message; resume_run_id?: number }>(`/pending-actions/${a.id}/${verb}`)
      chat.setMessages((l) => {
        const next = l.map((x) => ({ ...x, pending_actions: x.pending_actions.map((p) => (p.id === a.id ? res.action : p)) }))
        return res.message ? [...next, res.message] : next
      })
      if (res.message?.content) speak(res.message.content, fr, user?.voice.rate ?? 2)
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }

  const live = phase === 'listen' || phase === 'speak'
  const ring = (inset: number, alpha: number, delay: string) => (
    <span aria-hidden="true" className={`absolute rounded-full ${live ? (phase === 'listen' ? 'anim-ripple' : 'anim-breathe') : ''}`} style={{ inset, background: `rgba(217,164,65,${alpha})`, animationDelay: delay }} />
  )

  return (
    <div className="flex min-h-dvh flex-col bg-[#141D3B] text-[#F5F2EA]">
      <header className="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <Link to={convId ? `/c/${convId}` : '/'} className="inline-flex h-10 items-center gap-2 rounded-[10px] px-2 text-[14px] text-[#D9DEEE] no-underline hover:bg-white/10"><ArrowLeft size={18} />Retour au texte</Link>
        <span className="hidden items-center gap-2 font-logo text-[16px] font-extrabold sm:flex"><LogoMark size={24} />Noon · Mode vocal</span>
        <label className="flex"><span className="sr-only-noon">Langue</span>
          <select value={langCode} onChange={(e) => setLangCode(e.target.value)} className="h-10 rounded-[10px] border border-white/20 bg-white/10 px-2 text-[13.5px] text-[#F5F2EA] outline-none">
            {boot?.languages.map((l) => <option key={l.code} value={l.code} className="text-ink">{l.name}{l.family !== 'international' && !l.speech_to_text ? ' (écrit)' : ''}</option>)}
          </select>
        </label>
      </header>

      <main className="flex flex-1 flex-col items-center justify-center gap-7 px-4 pb-6">
        <button type="button" onClick={start} aria-label={phase === 'listen' ? 'Arrêter et envoyer' : 'Parler à Noon'} className="relative flex h-[260px] w-[260px] items-center justify-center rounded-full sm:h-[300px] sm:w-[300px]">
          {ring(0, 0.07, '1.6s')}
          {ring(28, 0.1, '.8s')}
          {ring(54, 0.16, '0s')}
          {phase === 'think' && <span aria-hidden="true" className="anim-spin absolute inset-[58px] rounded-full border-[3px] border-white/10 border-t-[#D9A441]" />}
          <span className={`relative flex h-[132px] w-[132px] items-center justify-center rounded-full shadow-[0_10px_40px_rgba(0,0,0,.35)] ${phase === 'think' ? 'bg-[#1F2E5C]' : 'bg-[#D9A441] text-[#141B2E]'} ${phase === 'speak' ? 'anim-breathe' : ''}`}>
            {phase === 'listen' ? (
              <span className="flex h-14 items-center gap-1.5" aria-hidden="true">
                {[0, 0.18, 0.36, 0.1, 0.28, 0.46, 0.08].map((d, i) => <span key={i} className="anim-bar w-[7px] rounded-[4px] bg-[#141B2E]" style={{ height: 14 + Math.min(42, level * 60 + 10), animationDelay: `${d}s` }} />)}
              </span>
            ) : phase === 'think' ? (
              <span className="flex gap-2" aria-hidden="true">{[0, 1, 2].map((i) => <span key={i} className="anim-dot h-3 w-3 rounded-full bg-[#F5F2EA]" style={{ animationDelay: `${i * 0.15}s` }} />)}</span>
            ) : phase === 'speak' ? <Square size={34} fill="currentColor" /> : <Mic size={46} />}
          </span>
        </button>

        <div aria-live="polite" className="anim-in flex max-w-[640px] flex-col items-center gap-3 text-center">
          <span className="text-[13px] font-semibold uppercase tracking-[.08em] text-[#D9A441]">{TITLES[phase]}</span>
          {heard && <p className="m-0 font-display text-[22px] leading-snug text-[#E8EBF4] sm:text-[26px]">« {heard} »</p>}
          {phase !== 'listen' && lastMain && <p className="m-0 max-h-[30vh] overflow-y-auto whitespace-pre-line text-[17px] leading-relaxed text-[#F5F2EA]">{lastMain}</p>}
          {phase !== 'listen' && lastFr && <p className="m-0 max-h-[20vh] overflow-y-auto whitespace-pre-line text-[15px] leading-relaxed text-[#C9CFE3]">{lastFr}</p>}
        </div>

        {last && last.steps.length > 0 && phase !== 'listen' && (
          <div className="flex w-full max-w-[520px] flex-col gap-1.5">
            {last.steps.map((s) => (
              <div key={s.id} className="flex items-center gap-2.5 rounded-[12px] bg-white/[.07] px-3 py-2 text-[13.5px]">
                <Tile letter={s.letter} hue={s.hue} size={26} /><span className="flex-1">{s.label}</span>
                <span className={`text-[12px] font-semibold ${s.state === 'done' ? 'text-[#BCD6A2]' : 'text-[#D9A441]'}`}>{s.status}</span>
              </div>
            ))}
          </div>
        )}
        {last?.pending_actions.filter((p) => p.status === 'pending').map((p) => (
          <div key={p.id} className="flex w-full max-w-[520px] flex-col gap-2.5 rounded-[16px] bg-[#F5F2EA] p-4 text-ink">
            <span className="text-[15px] font-semibold">{p.summary}</span>
            <div className="flex gap-2">
              <button type="button" onClick={() => resolve(p, 'approve')} className="h-11 rounded-[10px] bg-indigo px-4 text-[14px] font-semibold text-surface">Confirmer l'envoi</button>
              <button type="button" onClick={() => resolve(p, 'reject')} className="h-11 rounded-[10px] border border-line-strong px-4 text-[14px] font-semibold">Annuler</button>
            </div>
          </div>
        ))}
      </main>

      <footer className="flex flex-col items-center gap-3 px-4 pb-[max(24px,env(safe-area-inset-bottom))]">
        <div className="flex items-center gap-4">
          <button type="button" onClick={() => { session.current?.stop(); stopSpeaking(); setPhase('idle') }} aria-label="Couper le micro et la voix" className="flex h-14 w-14 items-center justify-center rounded-full bg-white/10 hover:bg-white/15"><MicOff size={22} /></button>
          <button type="button" onClick={() => navigate(convId ? `/c/${convId}` : '/')} aria-label="Terminer la session vocale" className="flex h-14 w-14 items-center justify-center rounded-full bg-[#B42318] hover:brightness-110"><span className="h-1 w-5 rounded bg-white" /></button>
          {convId && <Link to={`/c/${convId}`} aria-label="Voir la transcription" className="flex h-14 w-14 items-center justify-center rounded-full bg-white/10 text-[#F5F2EA] hover:bg-white/15"><MessageSquareText size={22} /></Link>}
        </div>
        <span className="max-w-md text-center text-[13px] text-[#C9CFE3]">Parlez naturellement. Touchez le cercle pour terminer votre phrase. Rien n'est envoyé ni publié sans votre accord.</span>
      </footer>
    </div>
  )
}
