import { Volume2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, Field, inputCls, PageHeader, Toggle, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { User, VoicePrefs } from '../lib/types'
import { canSpeak, speak } from '../lib/voice'

const VOICES: [string, string][] = [['Douce', 'Posée'], ['Chaleureuse', 'Comme à la maison'], ['Dynamique', 'Plus rapide']]
const RATES = ['Très lente', 'Lente', 'Normale', 'Rapide', 'Très rapide']

export default function Settings() {
  const { user, boot, setUser, refresh } = useApp()
  const toast = useToast()
  const [pwd, setPwd] = useState({ current_password: '', password: '' })
  if (!user || !boot) return null
  const lang = boot.languages.find((l) => l.code === user.language)

  const save = async (patch: Partial<User> | { voice: Partial<VoicePrefs> }) => {
    try {
      const res = await api.patch<{ user: User }>('/me', patch)
      setUser(res.user)
      refresh()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  const voice = (patch: Partial<VoicePrefs>) => save({ voice: patch })

  const changeCode = async () => {
    try {
      await api.patch('/me', pwd)
      setPwd({ current_password: '', password: '' })
      toast('Code modifié', 'success')
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }

  return (
    <div className="mx-auto flex w-full max-w-[760px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader title="Réglages" subtitle="Votre langue, votre voix et votre compte." />

      <Card className="flex flex-col gap-4 p-5">
        <h2 className="m-0 text-[16px] font-semibold">Ma langue</h2>
        <Field label="Noon me répond en" hint={lang && lang.family !== 'international' ? `Niveau de Noon en ${lang.name} : ${lang.quality_label.toLowerCase()}. Il progresse grâce à la Bibliothèque des savoirs.` : undefined}>
          <select className={inputCls} value={user.language} onChange={(e) => save({ language: e.target.value })}>
            {boot.languages.map((l) => <option key={l.code} value={l.code}>{l.name}{l.native_name && l.native_name !== l.name ? ` — ${l.native_name}` : ''}</option>)}
          </select>
        </Field>
        {lang && lang.family !== 'international' && (
          <Toggle checked={user.bilingual_replies} onChange={(v) => save({ bilingual_replies: v })} label="Ajouter la traduction en français" description="Noon répond dans votre langue, puis répète en français en dessous." />
        )}
        <Toggle checked={user.large_text} onChange={(v) => save({ large_text: v })} label="Grands caractères et réponses courtes" description="Texte plus gros partout, réponses plus simples." />
      </Card>

      <Card className="flex flex-col gap-4 p-5">
        <div className="flex items-center justify-between gap-2"><h2 className="m-0 text-[16px] font-semibold">Assistant vocal</h2><Badge tone={lang?.text_to_speech ? 'success' : 'warn'}>{lang?.text_to_speech ? `Voix disponible en ${lang.name}` : `Voix en ${lang?.name} bientôt disponible`}</Badge></div>
        <Toggle checked={user.voice.wake_word} onChange={(v) => voice({ wake_word: v })} label="Mot d'activation « Dis Noon »" description="Sur ordinateur : le micro se déclenche quand vous dites « Dis Noon » (bouton en bas à droite)." />
        <div className="flex min-h-[48px] items-center justify-between gap-4 rounded-[10px] bg-sunken/60 px-3 py-2">
          <span className="flex flex-col"><span className="text-[14.5px] font-medium">Raccourci clavier</span><span className="text-[12.5px] text-muted">Maintenir pour parler</span></span>
          <span className="flex gap-1"><kbd className="rounded-[6px] border border-line-strong bg-surface px-2 py-1 text-[12px]">Ctrl</kbd><kbd className="rounded-[6px] border border-line-strong bg-surface px-2 py-1 text-[12px]">Espace</kbd></span>
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-[13.5px] font-semibold">Voix</span>
          <div className="grid grid-cols-3 gap-2">
            {VOICES.map(([n, d]) => (
              <button key={n} type="button" aria-pressed={user.voice.voice === n} onClick={() => voice({ voice: n })} className={`flex flex-col items-start gap-1 rounded-[12px] border p-3 text-left ${user.voice.voice === n ? 'border-indigo bg-indigo-50' : 'border-line bg-surface'}`}>
                <span className="text-[14px] font-semibold">{n}</span><span className="text-[12px] text-muted">{d}</span>
              </button>
            ))}
          </div>
        </div>
        <label className="flex flex-col gap-1.5">
          <span className="flex justify-between text-[13.5px] font-semibold">Vitesse de parole <span className="font-medium text-muted">{RATES[user.voice.rate]}</span></span>
          <input type="range" min={0} max={4} value={user.voice.rate} onChange={(e) => voice({ rate: Number(e.target.value) })} className="accent-indigo" />
        </label>
        <Toggle checked={user.voice.auto_read} onChange={(v) => voice({ auto_read: v })} label="Lire les réponses à voix haute en mode vocal" />
        <Toggle checked={user.voice.confirm_aloud} onChange={(v) => voice({ confirm_aloud: v })} label="Confirmer à voix haute" description="Avant tout envoi ou publication" />
        <Button variant="secondary" className="self-start" disabled={!canSpeak(lang)} onClick={() => speak(user.language === 'fr' ? `Bonjour ${user.first_name}, je suis Noon. Je suis là pour vous aider.` : 'Noon', lang, user.voice.rate)}>
          <Volume2 size={16} />Écouter un exemple
        </Button>
      </Card>

      <Card className="flex flex-col gap-3 p-5">
        <h2 className="m-0 text-[16px] font-semibold">Mon offre</h2>
        <p className="m-0 text-[14.5px]">{boot.quota?.plan.name}{boot.quota?.remaining_today !== null && boot.quota ? ` · ${boot.quota.remaining_today} messages restants aujourd'hui` : ' · messages illimités'}</p>
        <Link to="/offres" className="text-[14px] font-semibold text-indigo">Voir les offres</Link>
      </Card>

      <Card className="flex flex-col gap-4 p-5">
        <h2 className="m-0 text-[16px] font-semibold">Mon compte</h2>
        <p className="m-0 text-[14px] text-ink-2">{user.name} · {[user.phone, user.email].filter(Boolean).join(' · ')}{user.group ? ` · ${user.group.name}` : ''}</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Code actuel"><input type="password" autoComplete="current-password" className={inputCls} value={pwd.current_password} onChange={(e) => setPwd({ ...pwd, current_password: e.target.value })} /></Field>
          <Field label="Nouveau code"><input type="password" autoComplete="new-password" className={inputCls} value={pwd.password} onChange={(e) => setPwd({ ...pwd, password: e.target.value })} minLength={4} /></Field>
        </div>
        <Button variant="secondary" className="self-start" disabled={!pwd.current_password || pwd.password.length < 4} onClick={changeCode}>Changer mon code</Button>
      </Card>
    </div>
  )
}
