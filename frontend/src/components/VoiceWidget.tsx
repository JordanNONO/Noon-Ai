import { Maximize2, Mic, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useApp } from '../lib/app'
import { splitLive, useChat } from '../lib/useChat'
import { canListen, canSpeak, deviceCanListen, listen, speak, stopSpeaking, type ListenSession } from '../lib/voice'
import { useToast } from './ui'

/**
 * Assistant vocal flottant (ordinateur) : clic, Ctrl+Espace maintenu, ou « Dis Noon »
 * si le mot d'activation est activé. Répond dans une carte et à voix haute.
 */
export default function VoiceWidget() {
  const { boot, user, refresh } = useApp()
  const toast = useToast()
  const loc = useLocation()
  const [open, setOpen] = useState(false)
  const [heard, setHeard] = useState('')
  const [listening, setListening] = useState(false)
  const [wakeOn, setWakeOn] = useState(false)
  const session = useRef<ListenSession | null>(null)
  const wakeRef = useRef<{ stop: () => void } | null>(null)

  const lang = boot?.languages.find((l) => l.code === user?.language)
  const fr = boot?.languages.find((l) => l.code === 'fr')
  const chat = useChat({
    onDone: (m) => {
      refresh().catch(() => {})
      if (user?.voice.auto_read && m.content && m.status !== 'error') {
        const target = canSpeak(lang) ? lang : fr
        speak(target === lang ? m.content : m.translation ?? m.content, target, user.voice.rate).catch(() => {})
      }
    },
  })

  const ask = useCallback(async (preset?: string) => {
    setOpen(true)
    stopSpeaking()
    if (preset) {
      setHeard(preset)
      chat.setConversationId(null)
      chat.setMessages([])
      await chat.send(preset, { language: user?.language })
      return
    }
    if (!lang || !canListen(lang)) {
      toast(`L'écoute en ${lang?.name ?? 'cette langue'} n'est pas encore disponible.`, 'error')
      return
    }
    setHeard('')
    setListening(true)
    const s = listen(lang, setHeard)
    session.current = s
    try {
      const r = await s.result
      setListening(false)
      if (!r.text) return
      setHeard(r.text)
      chat.setConversationId(null)
      chat.setMessages([])
      await chat.send(r.text, { language: user?.language, audio_token: r.audioToken })
    } catch (e) {
      setListening(false)
      toast((e as Error).message, 'error')
    }
  }, [chat, lang, toast, user?.language])

  // Ctrl + Espace maintenu : parler ; relâché : envoyer
  useEffect(() => {
    let held = false
    const down = (e: KeyboardEvent) => {
      if (e.ctrlKey && e.code === 'Space' && !held) {
        e.preventDefault()
        held = true
        ask()
      }
    }
    const up = (e: KeyboardEvent) => {
      if (held && (e.code === 'Space' || e.key === 'Control')) {
        held = false
        session.current?.stop()
      }
    }
    window.addEventListener('keydown', down)
    window.addEventListener('keyup', up)
    return () => {
      window.removeEventListener('keydown', down)
      window.removeEventListener('keyup', up)
    }
  }, [ask])

  // Mot d'activation « Dis Noon » (reconnaissance de l'appareil, français)
  useEffect(() => {
    if (!wakeOn || !user?.voice.wake_word || !deviceCanListen() || listening || chat.busy) return
    let stopped = false
    const loop = () => {
      if (stopped) return
      const s = listen({ ...fr!, speech_engine: 'device' }, (t) => {
        const m = /(?:dis|di|hey|eh)\s+n[oô]u?ne?[,\s]*(.*)$/i.exec(t)
        if (m) {
          stopped = true
          s.stop()
          setTimeout(() => (m[1]?.trim().length > 3 ? ask(m[1].trim()) : ask()), 300)
        }
      })
      wakeRef.current = s
      s.result.then(() => !stopped && setTimeout(loop, 250)).catch(() => {
        stopped = true
        setWakeOn(false)
      })
    }
    loop()
    return () => {
      stopped = true
      wakeRef.current?.stop()
    }
  }, [wakeOn, user?.voice.wake_word, listening, chat.busy, ask, fr])

  if (loc.pathname === '/vocal' || !user) return null
  const answer = [...chat.messages].reverse().find((m) => m.role === 'assistant')
  const [main] = answer ? (answer.status === 'streaming' ? splitLive(answer.content ?? '') : [answer.content ?? '']) : ['']
  const convId = chat.conversationId.current

  return (
    <div className="fixed bottom-6 right-6 z-30 hidden flex-col items-end gap-3 lg:flex">
      {open && (
        <section aria-label="Assistant vocal Noon" className="anim-in w-[380px] overflow-hidden rounded-[18px] bg-[#141D3B] text-[#F5F2EA] shadow-2xl">
          <div className="flex items-center gap-3 p-4">
            <span className={`flex h-10 w-10 flex-none items-center justify-center rounded-full bg-[#D9A441] text-ink ${listening ? 'anim-pulse' : ''}`}><Mic size={18} /></span>
            <div className="flex min-w-0 flex-1 flex-col">
              <span className="text-[14px] font-semibold">{listening ? "J'écoute…" : chat.busy ? 'Je réfléchis…' : 'Dis Noon'}</span>
              <span className="truncate text-[13px] text-[#C9CFE3]">{heard ? `« ${heard} »` : 'Parlez maintenant'}</span>
            </div>
            <button type="button" aria-label="Fermer l'assistant" onClick={() => { setOpen(false); session.current?.stop(); stopSpeaking() }} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-[#C9CFE3] hover:bg-white/10"><X size={17} /></button>
          </div>
          {main && <div className="max-h-[300px] overflow-y-auto whitespace-pre-line border-t border-white/10 px-4 py-3 text-[14.5px] leading-relaxed">{main}</div>}
          <div className="flex flex-wrap gap-2 border-t border-white/10 px-4 py-3">
            <button type="button" onClick={() => ask()} className="inline-flex h-9 items-center gap-1.5 rounded-[9px] bg-[#D9A441] px-3 text-[13px] font-semibold text-ink"><Mic size={14} />Parler encore</button>
            {convId && <Link to={`/c/${convId}`} onClick={() => setOpen(false)} className="inline-flex h-9 items-center rounded-[9px] bg-white/10 px-3 text-[13px] font-semibold text-[#F5F2EA] no-underline">Ouvrir la conversation</Link>}
            <Link to={`/vocal${convId ? `?c=${convId}` : ''}`} className="inline-flex h-9 items-center gap-1.5 rounded-[9px] bg-white/10 px-3 text-[13px] font-semibold text-[#F5F2EA] no-underline"><Maximize2 size={13} />Plein écran</Link>
          </div>
        </section>
      )}
      <div className="flex items-center gap-2">
        {user.voice.wake_word && deviceCanListen() && (
          <button type="button" aria-pressed={wakeOn} onClick={() => setWakeOn((v) => !v)} className={`h-10 rounded-full px-3.5 text-[12.5px] font-semibold shadow-md ${wakeOn ? 'bg-ochre-bright text-ink' : 'bg-surface text-ink-2'}`}>
            {wakeOn ? '« Dis Noon » activé' : 'Activer « Dis Noon »'}
          </button>
        )}
        <button type="button" onClick={() => ask()} aria-label="Parler à Noon (Ctrl + Espace)" title="Parler à Noon (Ctrl + Espace)" className={`flex h-14 w-14 items-center justify-center rounded-full bg-indigo text-surface shadow-lg hover:bg-indigo-700 ${wakeOn ? 'anim-pulse' : ''}`}>
          <Mic size={22} />
        </button>
      </div>
    </div>
  )
}
