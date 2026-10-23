import { Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Button, Field, inputCls, Modal, PageHeader, textareaCls, Tile, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Agent } from '../lib/types'
import { reloadAgents, useAgents } from '../lib/useAgents'

const THEMES: [string, string][] = [['tous', 'Tous'], ['general', 'Tout usage'], ['langue', 'Langues'], ['culture', 'Culture & histoire'], ['cuisine', 'Cuisine'], ['famille', 'Famille & cérémonies'], ['apprendre', 'Apprendre']]

interface Draft { id?: number; name: string; description: string; system_prompt: string; theme: string; starters: string; connector_ids: number[]; is_public: boolean }
const empty: Draft = { name: '', description: '', system_prompt: '', theme: 'general', starters: '', connector_ids: [], is_public: false }

export default function Assistants() {
  const agents = useAgents()
  const { boot, user, refresh } = useApp()
  const toast = useToast()
  const [theme, setTheme] = useState('tous')
  const [q, setQ] = useState('')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [busy, setBusy] = useState(false)

  const noon = agents.find((a) => a.is_default)
  const list = useMemo(() => agents.filter((a) => !a.is_default)
    .filter((a) => theme === 'tous' || a.theme === theme)
    .filter((a) => !q || (a.name + ' ' + a.description).toLowerCase().includes(q.toLowerCase())), [agents, theme, q])

  const edit = async (a: Agent) => {
    const full = await api.get<{ agent: Agent }>(`/agents/${a.id}`)
    setDraft({ id: a.id, name: a.name, description: a.description, system_prompt: full.agent.system_prompt ?? '', theme: a.theme, starters: a.starters.join('\n'), connector_ids: a.connectors.map((c) => c.id), is_public: a.is_public })
  }

  const save = async () => {
    if (!draft) return
    setBusy(true)
    const body = { ...draft, starters: draft.starters.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 6) }
    try {
      if (draft.id) await api.put(`/agents/${draft.id}`, body)
      else await api.post('/agents', body)
      await reloadAgents()
      refresh()
      toast(draft.id ? 'Assistant mis à jour' : 'Assistant créé', 'success')
      setDraft(null)
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }

  const remove = async (a: Agent) => {
    if (!window.confirm(`Supprimer l'assistant « ${a.name} » ?`)) return
    await api.del(`/agents/${a.id}`)
    await reloadAgents()
    refresh()
  }

  return (
    <div className="mx-auto flex w-full max-w-[1080px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title="Assistants"
        subtitle="Noon répond à tout. Ces assistants vont plus loin sur un sujet précis. Vous pouvez aussi créer le vôtre."
        actions={<Button onClick={() => setDraft({ ...empty })}><Plus size={16} />Créer un assistant</Button>}
      />

      <label className="relative">
        <span className="sr-only-noon">Rechercher un assistant</span>
        <Search size={17} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true" />
        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher : langue, conte, recette, devoirs…" className={inputCls + ' h-12 pl-10'} />
      </label>

      <div role="tablist" aria-label="Filtrer par thème" className="flex flex-wrap gap-1.5">
        {THEMES.map(([k, l]) => (
          <button key={k} type="button" role="tab" aria-selected={theme === k} onClick={() => setTheme(k)} className={`h-9 rounded-full border px-3.5 text-[13.5px] ${theme === k ? 'border-ink bg-ink font-semibold text-surface' : 'border-line-strong bg-surface font-medium text-ink-2'}`}>{l}</button>
        ))}
      </div>

      {noon && (
        <section className="flex flex-wrap items-center gap-5 rounded-[16px] border border-line bg-surface p-5">
          <Tile letter="N" hue="indigo" size={56} />
          <div className="flex min-w-[240px] flex-1 flex-col gap-1">
            <span className="text-[18px] font-semibold">Noon</span>
            <span className="text-[14px] leading-relaxed text-muted">{noon.description}</span>
          </div>
          <Link to="/" className="inline-flex h-11 items-center rounded-[10px] bg-indigo px-5 text-[14.5px] font-semibold text-surface no-underline">Démarrer</Link>
        </section>
      )}

      <div className="flex items-baseline justify-between"><h2 className="m-0 text-[15px] font-semibold">Assistants spécialisés</h2><span className="text-[13px] text-muted">{list.length} assistant{list.length > 1 ? 's' : ''}</span></div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {list.map((a) => (
          <article key={a.id} className="flex flex-col gap-2.5 rounded-[14px] border border-line bg-surface p-4">
            <div className="flex items-center gap-3">
              <Tile letter={a.initial} hue={a.hue} size={40} />
              <div className="flex min-w-0 flex-1 flex-col">
                <span className="text-[15px] font-semibold">{a.name}</span>
                <span className="text-[12px] text-muted">{a.theme_label}{!a.is_public && ' · Personnel'}</span>
              </div>
              {a.can_edit && (
                <div className="flex">
                  <button type="button" aria-label={`Modifier ${a.name}`} onClick={() => edit(a)} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><Pencil size={15} /></button>
                  <button type="button" aria-label={`Supprimer ${a.name}`} onClick={() => remove(a)} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><Trash2 size={15} /></button>
                </div>
              )}
            </div>
            <p className="m-0 flex-1 text-[13.5px] leading-relaxed text-ink-2">{a.description}</p>
            <div className="flex items-center justify-between gap-2 border-t border-line pt-2.5">
              <span className="truncate text-[12px] text-muted">{a.connectors.map((c) => c.short).join(' · ') || (a.author ? `Par ${a.author}` : '')}</span>
              <Link to={`/?assistant=${a.id}`} className="inline-flex min-h-[36px] items-center rounded-[8px] border border-line-strong px-3 text-[13px] font-semibold text-ink no-underline hover:bg-sunken">Démarrer</Link>
            </div>
          </article>
        ))}
      </div>

      <Modal open={!!draft} wide title={draft?.id ? 'Modifier l’assistant' : 'Créer un assistant'} onClose={() => setDraft(null)}
        footer={<><Button variant="secondary" onClick={() => setDraft(null)}>Annuler</Button><Button onClick={save} disabled={busy || !draft?.name || !draft.description || !draft.system_prompt}>{busy ? 'Enregistrement…' : 'Enregistrer'}</Button></>}>
        {draft && (
          <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nom"><input className={inputCls} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} maxLength={60} placeholder="ex. Coach marché" /></Field>
              <Field label="Thème">
                <select className={inputCls} value={draft.theme} onChange={(e) => setDraft({ ...draft, theme: e.target.value })}>{THEMES.slice(1).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
              </Field>
            </div>
            <Field label="Description courte"><input className={inputCls} value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} maxLength={240} placeholder="Ce que fait cet assistant, en une phrase" /></Field>
            <Field label="Consignes" hint="Décrivez qui est l'assistant, à qui il parle, comment il répond, ce qu'il ne doit pas faire.">
              <textarea rows={7} className={textareaCls} value={draft.system_prompt} onChange={(e) => setDraft({ ...draft, system_prompt: e.target.value })} />
            </Field>
            <Field label="Suggestions de questions" hint="Une par ligne, 6 au maximum."><textarea rows={3} className={textareaCls} value={draft.starters} onChange={(e) => setDraft({ ...draft, starters: e.target.value })} /></Field>
            {(boot?.connectors.length ?? 0) > 0 && (
              <fieldset className="m-0 flex flex-col gap-1 border-0 p-0">
                <legend className="mb-1 text-[13.5px] font-semibold">Connecteurs autorisés</legend>
                {boot!.connectors.map((c) => (
                  <label key={c.id} className="flex min-h-[40px] items-center gap-2.5 text-[14px]">
                    <input type="checkbox" className="h-[18px] w-[18px] accent-indigo" checked={draft.connector_ids.includes(c.id)} onChange={(e) => setDraft({ ...draft, connector_ids: e.target.checked ? [...draft.connector_ids, c.id] : draft.connector_ids.filter((x) => x !== c.id) })} />
                    <Tile letter={c.letter} hue={c.hue} size={24} />{c.name}
                  </label>
                ))}
              </fieldset>
            )}
            {user?.role === 'admin' && (
              <label className="flex items-center gap-2.5 text-[14px]">
                <input type="checkbox" className="h-[18px] w-[18px] accent-indigo" checked={draft.is_public} onChange={(e) => setDraft({ ...draft, is_public: e.target.checked })} />
                Proposer cet assistant à tout le monde <Badge tone="indigo">Administrateur</Badge>
              </label>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}
