import { Plus, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge, Button, Field, inputCls, Modal, PageHeader, Spinner, textareaCls, Tile, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'

interface FieldDef { key: string; label: string; secret?: boolean; required?: boolean; placeholder?: string; multiline?: boolean }
interface Connector {
  id: number; name: string; short: string; type: string; type_label: string; auth: string; oauth_ready: boolean; fields: FieldDef[]
  config: Record<string, string>; tools: { name: string; label: string; level: string }[]; is_custom: boolean; status: 'on' | 'off' | 'custom'
  is_connected: boolean; is_sensitive: boolean; letter: string; hue: string; description: string
  perms: { read: string; write: string; send: string }; agents: { id: number; name: string }[]
}
interface Data { connectors: Connector[]; types: { type: string; label: string }[]; can_manage: boolean }

const STATUS = { on: ['Connecté', 'success'], off: ['Disponible', 'neutral'], custom: ['Personnalisé', 'indigo'] } as const
const PERM_LABELS: Record<string, string> = { always: 'Toujours autoriser', ask: 'Demander à chaque fois', block: 'Bloquer' }
const LEVELS: [keyof Connector['perms'], string][] = [['read', 'Lire'], ['write', 'Créer / modifier'], ['send', 'Envoyer / publier']]

export default function Connectors() {
  const { refresh } = useApp()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const [data, setData] = useState<Data | null>(null)
  const [tab, setTab] = useState<'all' | 'on' | 'off' | 'custom'>('all')
  const [q, setQ] = useState('')
  const [selId, setSelId] = useState<number | null>(null)
  const [cfg, setCfg] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [custom, setCustom] = useState<{ name: string; type: string; description: string } | null>(null)

  const load = () => api.get<Data>('/connectors').then((d) => {
    setData(d)
    setSelId((s) => s ?? d.connectors[0]?.id ?? null)
  })
  useEffect(() => {
    load()
  }, [])
  useEffect(() => {
    const o = params.get('oauth')
    if (!o) return
    toast(o === 'ok' ? 'Connecteur relié avec succès.' : 'La connexion a échoué. Réessayez.', o === 'ok' ? 'success' : 'error')
    if (params.get('id')) setSelId(Number(params.get('id')))
    setParams({}, { replace: true })
    refresh()
  }, [params, setParams, toast, refresh])

  const sel = data?.connectors.find((c) => c.id === selId) ?? null
  useEffect(() => setCfg(sel?.config ?? {}), [sel?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const list = useMemo(() => (data?.connectors ?? []).filter((c) => tab === 'all' || c.status === tab).filter((c) => !q || c.name.toLowerCase().includes(q.toLowerCase())), [data, tab, q])

  if (!data) return <Spinner />
  const manage = data.can_manage

  const connect = async () => {
    if (!sel) return
    setBusy(true)
    try {
      const res = await api.post<{ redirect?: string; message?: string }>(`/connectors/${sel.id}/connect`, { config: cfg })
      if (res.redirect) {
        window.location.href = res.redirect
        return
      }
      toast(res.message ?? 'Connecté', 'success')
      await load()
      refresh()
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }
  const disconnect = async () => {
    if (!sel || !window.confirm(`Déconnecter ${sel.name} ?`)) return
    await api.post(`/connectors/${sel.id}/disconnect`)
    await load()
    refresh()
  }
  const setPerm = async (level: keyof Connector['perms'], value: string) => {
    if (!sel) return
    await api.put(`/connectors/${sel.id}`, { perms: { [level]: value } })
    load()
  }
  const createCustom = async () => {
    if (!custom) return
    try {
      const res = await api.post<{ connector: Connector }>('/connectors', custom)
      setCustom(null)
      await load()
      setSelId(res.connector.id)
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }

  return (
    <div className="flex min-h-0 flex-1 flex-wrap">
      <section className="flex min-w-0 flex-[999_1_520px] flex-col gap-6 px-4 py-8 sm:px-8">
        <PageHeader
          title="Connecteurs"
          subtitle="Reliez vos outils pour que Noon puisse lire et agir à votre place. Rien n'est envoyé sans votre accord."
          actions={manage && <Button onClick={() => setCustom({ name: '', type: 'http', description: '' })}><Plus size={16} />Connecteur personnalisé</Button>}
        />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div role="tablist" aria-label="Filtrer les connecteurs" className="flex gap-1 rounded-[10px] bg-sunken p-1">
            {([['all', 'Tous'], ['on', 'Connectés'], ['off', 'Disponibles'], ['custom', 'Personnalisés']] as const).map(([k, l]) => (
              <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`h-[34px] rounded-[7px] px-3 text-[13.5px] ${tab === k ? 'bg-surface font-semibold shadow-sm' : 'font-medium text-muted'}`}>{l}</button>
            ))}
          </div>
          <label className="relative min-w-[220px]">
            <span className="sr-only-noon">Rechercher un connecteur</span>
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true" />
            <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher un connecteur" className={inputCls + ' h-10 pl-9'} />
          </label>
        </div>
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
          {list.map((c) => (
            <button key={c.id} type="button" onClick={() => setSelId(c.id)} aria-pressed={selId === c.id}
              className={`flex flex-col items-start gap-2.5 rounded-[14px] bg-surface p-4 text-left ${selId === c.id ? 'border-[1.5px] border-indigo shadow-[0_0_0_3px_rgba(31,46,92,.12)]' : 'border-[1.5px] border-line'}`}>
              <span className="flex w-full items-center justify-between"><Tile letter={c.letter} hue={c.hue} size={40} /><Badge tone={STATUS[c.status][1]}>{STATUS[c.status][0]}</Badge></span>
              <span className="text-[15px] font-semibold">{c.name}</span>
              <span className="text-[13px] leading-snug text-muted">{c.description}</span>
            </button>
          ))}
        </div>
      </section>

      {sel && (
        <aside aria-label="Détails du connecteur" className="flex w-full flex-col gap-5 border-t border-line bg-surface px-6 py-7 lg:w-[360px] lg:flex-none lg:border-l lg:border-t-0">
          <div className="flex items-center gap-3.5">
            <Tile letter={sel.letter} hue={sel.hue} size={52} />
            <div className="flex flex-col gap-1">
              <span className="text-[18px] font-semibold">{sel.name}</span>
              <Badge tone={STATUS[sel.status][1]} className="self-start">{sel.is_connected ? 'Connecté' : STATUS[sel.status][0]}</Badge>
            </div>
          </div>
          <p className="m-0 text-[14px] leading-relaxed text-ink-2">{sel.description}</p>

          <div className="flex flex-col gap-2">
            <span className="text-[13px] font-semibold">Ce que Noon peut faire</span>
            {LEVELS.filter(([lvl]) => sel.tools.some((t) => t.level === lvl)).map(([lvl, label]) => (
              <label key={lvl} className="flex min-h-[42px] items-center justify-between gap-2 border-b border-line text-[14px]">
                {label}
                <select disabled={!manage} value={sel.perms[lvl]} onChange={(e) => setPerm(lvl, e.target.value)} className="h-8 rounded-[8px] border border-line-strong bg-surface px-1.5 text-[13px]">
                  {Object.entries(PERM_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
              </label>
            ))}
            <span className="text-[12px] text-muted">{sel.tools.map((t) => t.label).join(' · ')}</span>
          </div>

          <div className="flex flex-col gap-2">
            <span className="text-[13px] font-semibold">Assistants qui l'utilisent</span>
            <div className="flex flex-wrap gap-1.5">
              <span className="flex h-[30px] items-center rounded-full bg-sunken px-2.5 text-[13px]">Noon</span>
              {sel.agents.map((a) => <span key={a.id} className="flex h-[30px] items-center rounded-full bg-sunken px-2.5 text-[13px]">{a.name}</span>)}
            </div>
          </div>

          {manage && !sel.is_connected && sel.auth === 'credentials' && sel.fields.length > 0 && (
            <div className="flex flex-col gap-3">
              {sel.fields.map((f) => (
                <Field key={f.key} label={f.label + (f.required ? '' : ' (facultatif)')}>
                  {f.multiline
                    ? <textarea rows={3} className={textareaCls} value={cfg[f.key] === '__set__' ? '' : cfg[f.key] ?? ''} placeholder={cfg[f.key] === '__set__' ? 'Déjà renseigné' : f.placeholder} onChange={(e) => setCfg({ ...cfg, [f.key]: e.target.value })} />
                    : <input type={f.secret ? 'password' : 'text'} autoComplete="off" className={inputCls} value={cfg[f.key] === '__set__' ? '' : cfg[f.key] ?? ''} placeholder={cfg[f.key] === '__set__' ? 'Déjà renseigné' : f.placeholder} onChange={(e) => setCfg({ ...cfg, [f.key]: e.target.value })} />}
                </Field>
              ))}
            </div>
          )}

          <div className="mt-auto flex flex-col gap-2">
            {!manage ? (
              <span className="text-[12.5px] text-muted">Seul un administrateur peut relier ou retirer un connecteur.</span>
            ) : sel.is_connected ? (
              <Button variant="danger" onClick={disconnect}>Déconnecter</Button>
            ) : (
              <Button onClick={connect} disabled={busy || (sel.auth === 'oauth_google' && !sel.oauth_ready)}>{busy ? 'Vérification…' : `Connecter ${sel.name}`}</Button>
            )}
            {manage && sel.auth === 'oauth_google' && !sel.oauth_ready && !sel.is_connected && <span className="text-[12px] text-warn">La connexion Google doit d'abord être configurée sur le serveur (GOOGLE_CLIENT_ID et GOOGLE_CLIENT_SECRET).</span>}
            <span className="text-center text-[12px] text-muted">Vous pouvez retirer la connexion à tout moment.</span>
          </div>
        </aside>
      )}

      <Modal open={!!custom} title="Connecteur personnalisé" onClose={() => setCustom(null)} footer={<><Button variant="secondary" onClick={() => setCustom(null)}>Annuler</Button><Button onClick={createCustom} disabled={!custom?.name || !custom.description}>Créer</Button></>}>
        {custom && (
          <div className="flex flex-col gap-4">
            <Field label="Nom"><input className={inputCls} value={custom.name} onChange={(e) => setCustom({ ...custom, name: e.target.value })} placeholder="ex. Site de l'association" /></Field>
            <Field label="Type">
              <select className={inputCls} value={custom.type} onChange={(e) => setCustom({ ...custom, type: e.target.value })}>{data.types.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}</select>
            </Field>
            <Field label="Description"><input className={inputCls} value={custom.description} onChange={(e) => setCustom({ ...custom, description: e.target.value })} placeholder="Ce que les assistants peuvent faire avec" /></Field>
          </div>
        )}
      </Modal>
    </div>
  )
}
