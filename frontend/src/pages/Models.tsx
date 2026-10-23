import { AlertTriangle, Plus, Settings2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, Button, Field, inputCls, Modal, PageHeader, Spinner, Tile, Toggle, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'

interface Model {
  id: number; name: string; provider: string; family: 'local' | 'free' | 'paid'; family_label: string; driver: string; base_url: string | null
  model_name: string; letter: string; hue: string; description: string | null; tags: string[]; warning: string | null; is_enabled: boolean
  is_configured: boolean; has_api_key: boolean; api_key_env: string | null; supports_tools: boolean; supports_vision: boolean; is_advanced: boolean
  cost_in_per_mtok: number; cost_out_per_mtok: number; priority: number
}
interface Rule { id: number; label: string; category: string; level: string; local_only: boolean; ai_model_id: number | null; note: string | null }
interface Data { families: { key: string; title: string; desc: string; count: number; enabled: number }[]; models: Model[]; rules: Rule[]; auto_fallback: boolean; can_manage: boolean }

const FAMILY_COLORS: Record<string, string> = { local: '#2F6B3A', free: '#7A4E0E', paid: '#A0452A' }
const BADGE: Record<string, 'success' | 'ochre' | 'warn'> = { local: 'success', free: 'ochre', paid: 'warn' }
const blank = { name: '', provider_label: '', family: 'local', driver: 'ollama', model_name: '', base_url: '', api_key: '', description: '' }

export default function Models() {
  const { refresh } = useApp()
  const toast = useToast()
  const [data, setData] = useState<Data | null>(null)
  const [fam, setFam] = useState<string | null>(null)
  const [edit, setEdit] = useState<(typeof blank & { id?: number }) | null>(null)
  const [testing, setTesting] = useState<number | null>(null)

  const load = () => api.get<Data>('/models').then(setData)
  useEffect(() => {
    load()
  }, [])
  if (!data) return <Spinner />
  const manage = data.can_manage
  const list = data.models.filter((m) => !fam || m.family === fam)

  const toggle = async (m: Model) => {
    await api.put(`/models/${m.id}`, { is_enabled: !m.is_enabled })
    await load()
    refresh()
  }
  const test = async (m: Model) => {
    setTesting(m.id)
    try {
      const r = await api.post<{ reply: string; ms: number }>(`/models/${m.id}/test`)
      toast(`${m.name} répond (${(r.ms / 1000).toFixed(1)} s) : « ${r.reply.slice(0, 80)} »`, 'success')
    } catch (e) {
      toast(`${m.name} : ${(e as Error).message}`, 'error')
    } finally {
      setTesting(null)
    }
  }
  const save = async () => {
    if (!edit) return
    const body = { ...edit, base_url: edit.base_url || null, api_key: edit.api_key || undefined }
    try {
      if (edit.id) await api.put(`/models/${edit.id}`, body)
      else await api.post('/models', { ...body, is_enabled: true })
      setEdit(null)
      await load()
      refresh()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  const setRule = async (r: Rule, patch: Partial<Rule>) => {
    await api.put(`/routing-rules/${r.id}`, { ai_model_id: r.ai_model_id, local_only: r.local_only, ...patch })
    load()
  }

  return (
    <div className="flex min-h-0 flex-1 flex-wrap">
      <section className="flex min-w-0 flex-[999_1_560px] flex-col gap-6 px-4 py-8 sm:px-8">
        <PageHeader
          title="Modèles IA"
          subtitle="Les cerveaux que Noon peut utiliser. Les modèles gratuits installés sur nos serveurs passent en premier."
          actions={manage && <Button onClick={() => setEdit({ ...blank })}><Plus size={16} />Ajouter un modèle</Button>}
        />
        <div className="grid gap-3 sm:grid-cols-3">
          {data.families.map((f) => (
            <button key={f.key} type="button" aria-pressed={fam === f.key} onClick={() => setFam(fam === f.key ? null : f.key)}
              className={`flex flex-col items-start gap-2 rounded-[14px] bg-surface p-4 text-left ${fam === f.key ? 'border-[1.5px] border-indigo shadow-[0_0_0_3px_rgba(31,46,92,.12)]' : 'border-[1.5px] border-line'}`}>
              <span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full" style={{ background: FAMILY_COLORS[f.key] }} /><span className="text-[15px] font-semibold">{f.title}</span></span>
              <span className="text-[13px] leading-snug text-muted">{f.desc}</span>
              <span className="text-[12.5px] font-semibold text-ink-2">{f.count} modèles · {f.enabled} activés</span>
            </button>
          ))}
        </div>
        <div className="flex items-baseline justify-between">
          <h2 className="m-0 text-[15px] font-semibold">{fam ? data.families.find((f) => f.key === fam)?.title : 'Tous les modèles'}</h2>
          {fam && <button type="button" onClick={() => setFam(null)} className="text-[13px] font-semibold text-indigo">Tout afficher</button>}
        </div>
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(250px,1fr))]">
          {list.map((m) => (
            <article key={m.id} className="flex flex-col gap-2.5 rounded-[14px] border border-line bg-surface p-4">
              <div className="flex items-center gap-3">
                <Tile letter={m.letter} hue={m.hue} size={40} />
                <div className="flex min-w-0 flex-1 flex-col"><span className="text-[15px] font-semibold">{m.name}</span><span className="text-[12px] text-muted">{m.provider}</span></div>
                {manage && (
                  <label className="flex"><span className="sr-only-noon">Activer {m.name}</span>
                    <input type="checkbox" className="h-5 w-5 accent-indigo" checked={m.is_enabled} onChange={() => toggle(m)} />
                  </label>
                )}
              </div>
              <Badge tone={BADGE[m.family]} className="self-start">{m.family_label}</Badge>
              <span className="flex-1 text-[13px] leading-relaxed text-ink-2">{m.description}</span>
              <div className="flex flex-wrap gap-1">{m.tags.map((t) => <span key={t} className="rounded-full bg-sunken px-2 py-0.5 text-[11.5px] text-ink-2">{t}</span>)}</div>
              {m.warning && <span className="flex items-start gap-1.5 text-[12.5px] text-warn"><AlertTriangle size={14} className="mt-0.5 flex-none" />{m.warning}</span>}
              {!m.is_configured && <span className="text-[12.5px] text-danger">Clé d'API manquante{m.api_key_env ? ` (${m.api_key_env})` : ''}.</span>}
              {manage && (
                <div className="flex gap-1.5 border-t border-line pt-2.5">
                  <Button size="sm" variant="secondary" disabled={testing === m.id || !m.is_configured} onClick={() => test(m)}>{testing === m.id ? 'Essai…' : 'Essayer'}</Button>
                  <Button size="sm" variant="ghost" onClick={() => setEdit({ id: m.id, name: m.name, provider_label: m.provider, family: m.family, driver: m.driver, model_name: m.model_name, base_url: m.base_url ?? '', api_key: '', description: m.description ?? '' })}><Settings2 size={14} />Réglages</Button>
                </div>
              )}
            </article>
          ))}
        </div>
      </section>

      <aside aria-label="Règles de choix du modèle" className="flex w-full flex-col gap-4 border-t border-line bg-surface px-6 py-7 lg:w-[360px] lg:flex-none lg:border-l lg:border-t-0">
        <div className="flex flex-col gap-1"><span className="text-[16px] font-semibold">Règles de choix</span><span className="text-[13px] text-muted">Noon applique ces règles avant chaque réponse.</span></div>
        {data.rules.map((r) => (
          <div key={r.id} className="flex flex-col gap-2 rounded-[12px] border border-line p-3">
            <span className="flex items-center gap-2 text-[14px] font-semibold"><span className={`h-2 w-2 rounded-full ${r.level === 'sensitive' ? 'bg-danger' : r.level === 'large' ? 'bg-ochre-bright' : 'bg-success'}`} />{r.label}</span>
            <label className="flex items-center gap-2 text-[13px]"><span className="text-muted">Utiliser</span>
              <select disabled={!manage} value={r.ai_model_id ?? ''} onChange={(e) => setRule(r, { ai_model_id: e.target.value ? Number(e.target.value) : null })} className="h-8 min-w-0 flex-1 rounded-[8px] border border-line-strong bg-surface px-1.5 text-[13px]">
                <option value="">Choix automatique</option>
                {data.models.filter((m) => m.is_enabled && (!r.local_only || m.family === 'local')).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" disabled={!manage} className="h-4 w-4 accent-indigo" checked={r.local_only} onChange={(e) => setRule(r, { local_only: e.target.checked })} />Nos serveurs uniquement</label>
            {r.note && <span className="text-[12px] text-muted">{r.note}</span>}
          </div>
        ))}
        <Toggle checked={data.auto_fallback} label="Bascule automatique" description="Si un modèle est saturé ou en panne, passer au suivant" onChange={async (v) => { if (manage) { await api.put('/settings/fallback', { auto_fallback: v }); load() } }} />
      </aside>

      <Modal open={!!edit} title={edit?.id ? 'Réglages du modèle' : 'Ajouter un modèle'} onClose={() => setEdit(null)} footer={<><Button variant="secondary" onClick={() => setEdit(null)}>Annuler</Button><Button onClick={save} disabled={!edit?.name || !edit.model_name || !edit.provider_label}>Enregistrer</Button></>}>
        {edit && (
          <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nom affiché"><input className={inputCls} value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
              <Field label="Fournisseur"><input className={inputCls} value={edit.provider_label} onChange={(e) => setEdit({ ...edit, provider_label: e.target.value })} placeholder="ex. Mistral AI" /></Field>
              <Field label="Catégorie">
                <select className={inputCls} value={edit.family} onChange={(e) => setEdit({ ...edit, family: e.target.value })}><option value="local">Sur nos serveurs</option><option value="free">API gratuite</option><option value="paid">API payante</option></select>
              </Field>
              <Field label="Type de connexion">
                <select className={inputCls} value={edit.driver} onChange={(e) => setEdit({ ...edit, driver: e.target.value })}><option value="ollama">Ollama (nos serveurs)</option><option value="openai">Compatible OpenAI</option><option value="anthropic">Claude (Anthropic)</option></select>
              </Field>
            </div>
            <Field label="Identifiant du modèle" hint="ex. qwen3:8b, mistral-small-latest, claude-opus-5-5"><input className={inputCls} value={edit.model_name} onChange={(e) => setEdit({ ...edit, model_name: e.target.value })} /></Field>
            <Field label="Adresse de l'API (facultatif)" hint="Laisser vide pour l'adresse par défaut."><input className={inputCls} value={edit.base_url} onChange={(e) => setEdit({ ...edit, base_url: e.target.value })} placeholder="https://api.exemple.com/v1" /></Field>
            {edit.driver !== 'ollama' && <Field label="Clé d'API" hint="Laisser vide pour garder la clé actuelle ou celle du fichier .env."><input type="password" autoComplete="off" className={inputCls} value={edit.api_key} onChange={(e) => setEdit({ ...edit, api_key: e.target.value })} /></Field>}
            <Field label="Description"><input className={inputCls} value={edit.description} onChange={(e) => setEdit({ ...edit, description: e.target.value })} /></Field>
          </div>
        )}
      </Modal>
    </div>
  )
}
