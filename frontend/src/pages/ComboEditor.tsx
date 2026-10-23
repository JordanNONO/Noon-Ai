import { ArrowRight, Play, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Badge, Button, Field, inputCls, Spinner, textareaCls, Tile, Toggle, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Combo, ComboStep } from '../lib/types'
import { useAgents } from '../lib/useAgents'

interface Draft { name: string; trigger_type: 'manual' | 'schedule'; schedule_cron: string; schedule_label: string; is_favorite: boolean; steps: ComboStep[] }

const SCHEDULES: [string, string][] = [['0 9 1 * *', 'Le 1er du mois, 9 h'], ['0 8 * * 1', 'Chaque lundi, 8 h'], ['0 7 * * *', 'Chaque jour, 7 h'], ['0 19 * * 0', 'Chaque dimanche, 19 h']]

export default function ComboEditor() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { boot, refresh } = useApp()
  const agents = useAgents()
  const [d, setD] = useState<Draft | null>(null)
  const [sel, setSel] = useState(0)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (id) {
      api.get<{ combo: Combo }>(`/combos/${id}`).then(({ combo }) => setD({
        name: combo.name, trigger_type: combo.trigger_type, schedule_cron: combo.schedule_cron ?? SCHEDULES[0][0],
        schedule_label: combo.schedule_label ?? SCHEDULES[0][1], is_favorite: combo.is_favorite, steps: combo.steps,
      }))
    } else if (agents.length) {
      const noon = agents.find((a) => a.is_default)!
      setD({ name: 'Nouveau parcours', trigger_type: 'manual', schedule_cron: SCHEDULES[0][0], schedule_label: SCHEDULES[0][1], is_favorite: false, steps: [newStep(noon.id)] })
    }
  }, [id, agents])

  if (!d) return <Spinner />
  const step = d.steps[sel]
  const agentOf = (aid: number) => agents.find((a) => a.id === aid)

  function newStep(agentId: number): ComboStep {
    return { agent: null, agent_id: agentId, task: 'Nouvelle étape', prompt: '', model_tier: 'standard', handoff: 'full', requires_validation: false, connector_ids: [] }
  }
  const setStep = (patch: Partial<ComboStep>) => setD({ ...d, steps: d.steps.map((s, i) => (i === sel ? { ...s, ...patch } : s)) })

  const save = async (run = false) => {
    setBusy(true)
    try {
      const body = { ...d, steps: d.steps.map(({ agent_id, task, prompt, model_tier, handoff, requires_validation, connector_ids }) => ({ agent_id, task, prompt, model_tier, handoff, requires_validation, connector_ids })) }
      const res = id ? await api.put<{ combo: Combo }>(`/combos/${id}`, body) : await api.post<{ combo: Combo }>('/combos', body)
      refresh()
      toast('Parcours enregistré', 'success')
      navigate(run ? `/?parcours=${res.combo.id}` : '/parcours')
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex w-full flex-col gap-6 px-4 py-8 sm:px-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <span className="text-[13px] text-muted">Parcours / {id ? 'Modifier' : 'Nouveau parcours'}</span>
          <label className="flex"><span className="sr-only-noon">Nom du parcours</span>
            <input value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} className="w-full max-w-xl border-0 border-b border-transparent bg-transparent font-display text-[28px] font-medium outline-none focus:border-line-strong" />
          </label>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" disabled={busy} onClick={() => save(false)}>Enregistrer</Button>
          <Button disabled={busy} onClick={() => save(true)}><Play size={12} fill="currentColor" />Enregistrer et lancer</Button>
        </div>
      </div>

      <section aria-label="Enchaînement" className="flex flex-wrap items-stretch gap-2.5 rounded-[16px] border border-line bg-surface p-5">
        <div className="flex min-w-[150px] flex-1 flex-col gap-2 rounded-[12px] border-[1.5px] border-dashed border-line-strong bg-ground p-3.5">
          <span className="text-[12px] text-muted">Déclencheur</span>
          <select className="h-9 rounded-[8px] border border-line bg-surface px-2 text-[13.5px]" value={d.trigger_type} onChange={(e) => setD({ ...d, trigger_type: e.target.value as Draft['trigger_type'] })} aria-label="Déclencheur">
            <option value="manual">À la demande</option>
            <option value="schedule">Planifié</option>
          </select>
          {d.trigger_type === 'schedule' && (
            <select className="h-9 rounded-[8px] border border-line bg-surface px-2 text-[13px]" value={d.schedule_cron} aria-label="Planification"
              onChange={(e) => setD({ ...d, schedule_cron: e.target.value, schedule_label: SCHEDULES.find((s) => s[0] === e.target.value)?.[1] ?? '' })}>
              {SCHEDULES.map(([c, l]) => <option key={c} value={c}>{l}</option>)}
            </select>
          )}
        </div>
        {d.steps.map((s, i) => {
          const a = agentOf(s.agent_id)
          return (
            <div key={i} className="flex flex-1 items-stretch gap-2.5">
              <span className="flex items-center text-line-strong" aria-hidden="true"><ArrowRight size={20} /></span>
              <button type="button" onClick={() => setSel(i)} aria-pressed={sel === i}
                className={`flex min-w-[170px] flex-1 flex-col gap-2 rounded-[12px] bg-surface p-3.5 text-left ${sel === i ? 'border-[1.5px] border-indigo shadow-[0_0_0_3px_rgba(31,46,92,.12)]' : 'border-[1.5px] border-line'}`}>
                <span className="flex items-center gap-2.5">
                  {a && <Tile letter={a.initial} hue={a.hue} size={32} />}
                  <span className="flex flex-col"><span className="text-[12px] text-muted">Étape {i + 1}</span><span className="text-[14px] font-semibold">{a?.name ?? '—'}</span></span>
                </span>
                <span className="text-[12.5px] leading-snug text-ink-2">{s.task}</span>
                {s.requires_validation && <Badge tone="warn" className="self-start">Validation humaine</Badge>}
              </button>
            </div>
          )
        })}
        {d.steps.length < 8 && (
          <div className="flex items-stretch gap-2.5">
            <span className="flex items-center text-line-strong" aria-hidden="true"><ArrowRight size={20} /></span>
            <button type="button" onClick={() => { setD({ ...d, steps: [...d.steps, newStep(agents.find((a) => a.is_default)!.id)] }); setSel(d.steps.length) }}
              className="flex min-h-[110px] w-[110px] flex-col items-center justify-center gap-1.5 rounded-[12px] border-[1.5px] border-dashed border-line-strong text-[13.5px] font-semibold text-ink-2 hover:bg-sunken">
              <Plus size={18} />Ajouter
            </button>
          </div>
        )}
      </section>

      {step && (
        <div className="flex flex-wrap items-start gap-5">
          <section aria-label="Réglages de l'étape" className="flex min-w-0 flex-[2_1_420px] flex-col gap-4 rounded-[16px] border border-line bg-surface p-5">
            <div className="flex items-center justify-between gap-3">
              <span className="text-[16px] font-semibold">Étape {sel + 1} · réglages</span>
              {d.steps.length > 1 && <Button size="sm" variant="danger" onClick={() => { setD({ ...d, steps: d.steps.filter((_, i) => i !== sel) }); setSel(Math.max(0, sel - 1)) }}><Trash2 size={14} />Retirer l'étape</Button>}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Assistant">
                <select className={inputCls} value={step.agent_id} onChange={(e) => setStep({ agent_id: Number(e.target.value) })}>{agents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>
              </Field>
              <Field label="Tâche (courte)"><input className={inputCls} value={step.task} onChange={(e) => setStep({ task: e.target.value })} maxLength={160} /></Field>
            </div>
            <Field label="Consigne"><textarea rows={4} className={textareaCls} value={step.prompt} onChange={(e) => setStep({ prompt: e.target.value })} placeholder="Ce que l'assistant doit produire à cette étape." /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Modèle">
                <select className={inputCls} value={step.model_tier} onChange={(e) => setStep({ model_tier: e.target.value as ComboStep['model_tier'] })}><option value="standard">Standard</option><option value="advanced">Avancé (raisonnement)</option></select>
              </Field>
              <Field label="Transmettre à l'étape suivante">
                <select className={inputCls} value={step.handoff} onChange={(e) => setStep({ handoff: e.target.value as ComboStep['handoff'] })}><option value="full">Le résultat complet</option><option value="summary">Un résumé</option><option value="files">Le document produit</option></select>
              </Field>
            </div>
            <Toggle checked={step.requires_validation} onChange={(v) => setStep({ requires_validation: v })} label="Demander ma validation avant de continuer" />
          </section>
          <section aria-label="Connecteurs de l'étape" className="flex min-w-0 flex-[1_1_260px] flex-col gap-2 rounded-[16px] border border-line bg-surface p-5">
            <span className="text-[14px] font-semibold">Connecteurs autorisés</span>
            {(boot?.connectors.length ?? 0) === 0 && <span className="text-[13.5px] text-muted">Aucun connecteur relié.</span>}
            {boot?.connectors.map((c) => (
              <label key={c.id} className="flex min-h-[42px] items-center gap-2.5 border-b border-line text-[14px] last:border-b-0">
                <Tile letter={c.letter} hue={c.hue} size={26} /><span className="flex-1">{c.name}</span>
                <input type="checkbox" className="h-[18px] w-[18px] accent-indigo" checked={step.connector_ids.includes(c.id)} onChange={(e) => setStep({ connector_ids: e.target.checked ? [...step.connector_ids, c.id] : step.connector_ids.filter((x) => x !== c.id) })} />
              </label>
            ))}
          </section>
        </div>
      )}
    </div>
  )
}
