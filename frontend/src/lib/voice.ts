import { api, ApiError } from './api'
import type { Language } from './types'

/**
 * Voix de Noon :
 *  - français / anglais : reconnaissance et synthèse de l'appareil (navigateur, téléphone) ;
 *  - langues bamiléké : enregistrement envoyé au service vocal de Noon, voix générée par ce service.
 */

const LOCALES: Record<string, string> = { fr: 'fr-FR', en: 'en-US' }

// Types minimaux de l'API Web Speech (non standardisée dans lib.dom)
interface SpeechRecognitionLike {
  lang: string
  continuous: boolean
  interimResults: boolean
  onresult: ((e: { resultIndex: number; results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal: boolean }> }) => void) | null
  onerror: ((e: { error: string }) => void) | null
  onend: (() => void) | null
  start: () => void
  stop: () => void
  abort: () => void
}

function recognitionCtor(): (new () => SpeechRecognitionLike) | null {
  const w = window as unknown as { SpeechRecognition?: new () => SpeechRecognitionLike; webkitSpeechRecognition?: new () => SpeechRecognitionLike }
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

export function deviceCanListen(): boolean {
  return !!recognitionCtor()
}

export function canListen(lang: Language | undefined): boolean {
  if (!lang) return false
  if (lang.speech_engine === 'device') return deviceCanListen() || typeof MediaRecorder !== 'undefined'
  return lang.speech_to_text && typeof MediaRecorder !== 'undefined'
}

export function canSpeak(lang: Language | undefined): boolean {
  if (!lang) return false
  if (lang.speech_engine === 'device') return 'speechSynthesis' in window
  return lang.text_to_speech
}

export interface ListenResult { text: string; audioToken?: string }

export interface ListenSession {
  stop: () => void
  result: Promise<ListenResult>
}

/** Écoute une phrase. onPartial reçoit le texte reconnu en direct (voix de l'appareil). */
export function listen(lang: Language, onPartial?: (t: string) => void, onLevel?: (v: number) => void): ListenSession {
  if (lang.speech_engine === 'device' && deviceCanListen()) return listenDevice(lang.code, onPartial)
  return listenService(lang.code, onLevel)
}

function listenDevice(code: string, onPartial?: (t: string) => void): ListenSession {
  const Ctor = recognitionCtor()!
  const rec = new Ctor()
  rec.lang = LOCALES[code] ?? code
  rec.continuous = false
  rec.interimResults = true
  let finalText = ''
  let interim = ''
  const result = new Promise<ListenResult>((resolve, reject) => {
    rec.onresult = (e) => {
      interim = ''
      for (let i = e.resultIndex; i < e.results.length; i++) {
        const r = e.results[i]
        if (r.isFinal) finalText += r[0].transcript
        else interim += r[0].transcript
      }
      onPartial?.((finalText + ' ' + interim).trim())
    }
    rec.onerror = (e) => {
      if (e.error === 'no-speech' || e.error === 'aborted') resolve({ text: finalText.trim() })
      else reject(new Error(e.error === 'not-allowed' ? 'Autorisez le micro pour parler à Noon.' : 'Je n\'ai pas bien entendu. Réessayez.'))
    }
    rec.onend = () => resolve({ text: (finalText || interim).trim() })
  })
  rec.start()
  return { stop: () => rec.stop(), result }
}

function listenService(code: string, onLevel?: (v: number) => void): ListenSession {
  let recorder: MediaRecorder | null = null
  let streamRef: MediaStream | null = null
  let stopRequested = false
  let raf = 0

  const result = (async (): Promise<ListenResult> => {
    streamRef = await navigator.mediaDevices.getUserMedia({ audio: true }).catch(() => {
      throw new Error('Autorisez le micro pour parler à Noon.')
    })
    // Niveau sonore pour l'animation
    try {
      const ctx = new AudioContext()
      const analyser = ctx.createAnalyser()
      ctx.createMediaStreamSource(streamRef).connect(analyser)
      const data = new Uint8Array(analyser.frequencyBinCount)
      const tick = () => {
        analyser.getByteFrequencyData(data)
        onLevel?.(data.reduce((a, b) => a + b, 0) / data.length / 128)
        raf = requestAnimationFrame(tick)
      }
      tick()
    } catch {
      /* animation facultative */
    }
    const chunks: Blob[] = []
    recorder = new MediaRecorder(streamRef)
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data)
    const stopped = new Promise<void>((r) => (recorder!.onstop = () => r()))
    recorder.start()
    if (stopRequested) recorder.stop()
    // Arrêt automatique au bout de 30 secondes
    const guard = setTimeout(() => recorder?.state === 'recording' && recorder.stop(), 30000)
    await stopped
    clearTimeout(guard)
    cancelAnimationFrame(raf)
    streamRef.getTracks().forEach((t) => t.stop())

    const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' })
    const form = new FormData()
    form.append('audio', blob, 'voix.webm')
    form.append('language', code)
    const res = await api.upload<{ text: string; audio_token: string }>('/speech/transcribe', form).catch((e: ApiError) => {
      throw new Error(e.message)
    })
    return { text: res.text, audioToken: res.audio_token }
  })()

  return {
    stop: () => {
      stopRequested = true
      if (recorder?.state === 'recording') recorder.stop()
    },
    result,
  }
}

/* ---------------- Synthèse vocale ---------------- */

let currentAudio: HTMLAudioElement | null = null

export function stopSpeaking(): void {
  if ('speechSynthesis' in window) window.speechSynthesis.cancel()
  if (currentAudio) {
    currentAudio.pause()
    currentAudio = null
  }
}

/** Nettoie le Markdown avant lecture à voix haute. */
export function speakable(md: string): string {
  return md
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/[#*_>`~|]/g, '')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/\n{2,}/g, '. ')
    .replace(/\s+/g, ' ')
    .trim()
}

const RATES = [0.75, 0.9, 1, 1.15, 1.3]

export async function speak(text: string, lang: Language | undefined, rate = 2, onEnd?: () => void): Promise<void> {
  stopSpeaking()
  const clean = speakable(text)
  if (!clean || !lang) return onEnd?.()

  if (lang.speech_engine === 'device') {
    const u = new SpeechSynthesisUtterance(clean)
    u.lang = LOCALES[lang.code] ?? lang.code
    u.rate = RATES[rate] ?? 1
    const voice = window.speechSynthesis.getVoices().find((v) => v.lang.toLowerCase().startsWith(lang.code))
    if (voice) u.voice = voice
    u.onend = () => onEnd?.()
    u.onerror = () => onEnd?.()
    window.speechSynthesis.speak(u)
    return
  }

  const blob = await api.blob('/speech/synthesize', { text: clean.slice(0, 3000), language: lang.code })
  const audio = new Audio(URL.createObjectURL(blob))
  audio.playbackRate = RATES[rate] ?? 1
  audio.onended = () => onEnd?.()
  currentAudio = audio
  await audio.play()
}
