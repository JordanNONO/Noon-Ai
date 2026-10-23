import { Mic, Volume2 } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../lib/api'
import { useApp } from '../lib/app'
import type { User } from '../lib/types'
import { Button, Field, inputCls, Logo, NdopBand } from '../components/ui'

interface Options { open_registration: boolean; languages: { code: string; name: string; native_name: string | null }[] }

export default function Login() {
  const { signIn } = useApp()
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [opts, setOpts] = useState<Options | null>(null)
  const [form, setForm] = useState({ identifier: '', name: '', password: '', language: 'fr' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<Options>('/auth/options').then(setOpts).catch(() => setOpts({ open_registration: false, languages: [] }))
  }, [])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const id = form.identifier.trim()
      const res = mode === 'login'
        ? await api.post<{ token: string; user: User }>('/auth/login', { identifier: id, password: form.password })
        : await api.post<{ token: string; user: User }>('/auth/register', {
          name: form.name, password: form.password, language: form.language,
          ...(id.includes('@') ? { email: id } : { phone: id }),
        })
      await signIn(res.token, res.user)
    } catch (err) {
      const e2 = err as ApiError
      const flat: Record<string, string> = {}
      Object.entries(e2.errors ?? {}).forEach(([k, v]) => (flat[k] = v[0]))
      if (!Object.keys(flat).length) flat.identifier = e2.message
      setErrors(flat)
    } finally {
      setBusy(false)
    }
  }

  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  return (
    <div className="flex min-h-dvh flex-col bg-ground">
      <NdopBand />
      <div className="flex flex-1 items-center justify-center px-4 py-10">
        <div className="grid w-full max-w-[960px] items-center gap-10 md:grid-cols-2">
          <div className="flex flex-col gap-5">
            <Logo size={40} />
            <h1 className="m-0 font-display text-[34px] font-medium leading-tight sm:text-[44px]">L'intelligence artificielle qui parle votre langue.</h1>
            <p className="m-0 text-[16.5px] leading-relaxed text-ink-2">
              Posez vos questions à l'écrit ou à la voix, en français, en anglais ou en langue bamiléké. Noon vous répond, vous lit sa réponse et vous aide pour tout : lettres, papiers, devoirs des enfants, recettes, cérémonies…
            </p>
            <ul className="m-0 flex list-none flex-col gap-2.5 p-0 text-[15px]">
              <li className="flex items-center gap-2.5"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-50 text-indigo"><Mic size={17} /></span>Parlez simplement, comme à quelqu'un de la famille</li>
              <li className="flex items-center gap-2.5"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-ochre-50 text-ochre"><Volume2 size={17} /></span>Noon vous lit ses réponses à voix haute</li>
            </ul>
          </div>

          <form onSubmit={submit} className="flex flex-col gap-4 rounded-[18px] border border-line bg-surface p-6 shadow-sm sm:p-7">
            <div role="tablist" aria-label="Connexion ou inscription" className="grid grid-cols-2 gap-1 rounded-[12px] bg-sunken p-1">
              {(['login', 'register'] as const).map((m) => (
                <button key={m} type="button" role="tab" aria-selected={mode === m} disabled={m === 'register' && opts?.open_registration === false}
                  onClick={() => { setMode(m); setErrors({}) }}
                  className={`h-10 rounded-[9px] text-[14.5px] ${mode === m ? 'bg-surface font-semibold shadow-sm' : 'font-medium text-muted'} disabled:opacity-40`}>
                  {m === 'login' ? 'Se connecter' : 'Créer un compte'}
                </button>
              ))}
            </div>

            {mode === 'register' && (
              <Field label="Votre nom" error={errors.name}>
                <input className={inputCls} value={form.name} onChange={set('name')} autoComplete="name" required placeholder="ex. Maman Jeanne" />
              </Field>
            )}
            <Field label="Numéro de téléphone ou e-mail" error={errors.identifier ?? errors.phone ?? errors.email} hint="Par exemple +237 6 90 00 00 00">
              <input className={inputCls} value={form.identifier} onChange={set('identifier')} autoComplete="username" inputMode="email" required />
            </Field>
            <Field label={mode === 'login' ? 'Votre code' : 'Choisissez un code (4 chiffres ou plus)'} error={errors.password}>
              <input className={inputCls} type="password" value={form.password} onChange={set('password')} autoComplete={mode === 'login' ? 'current-password' : 'new-password'} required minLength={4} />
            </Field>
            {mode === 'register' && (
              <Field label="Quelle langue parlez-vous le mieux ?" error={errors.language} hint="Noon vous répondra dans cette langue. Vous pourrez changer plus tard.">
                <select className={inputCls} value={form.language} onChange={set('language')}>
                  {(opts?.languages ?? [{ code: 'fr', name: 'Français', native_name: null }]).map((l) => (
                    <option key={l.code} value={l.code}>{l.name}{l.native_name && l.native_name !== l.name ? ` — ${l.native_name}` : ''}</option>
                  ))}
                </select>
              </Field>
            )}
            <Button type="submit" size="lg" disabled={busy}>{busy ? 'Un instant…' : mode === 'login' ? 'Entrer' : 'Créer mon compte gratuit'}</Button>
            {mode === 'register' && <p className="m-0 text-center text-[12.5px] text-muted">Gratuit pour commencer. Vos conversations restent privées.</p>}
          </form>
        </div>
      </div>
    </div>
  )
}
