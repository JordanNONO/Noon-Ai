import { Check, KeyRound, Plus, Search, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, Button, Card, Field, inputCls, Modal, PageHeader, Spinner, Toggle, useToast } from '../components/ui'
import { api } from '../lib/api'
import type { Payment, Plan, User } from '../lib/types'
import { eur, fcfa } from './Plans'

interface Group { id: number; name: string; kind: string; kind_label: string; description: string | null; monthly_token_budget: number | null; members: number }
type Tab = 'members' | 'groups' | 'billing' | 'settings'

export default function Admin() {
  const [tab, setTab] = useState<Tab>('members')
  const tabs: [Tab, string][] = [['members', 'Membres'], ['groups', 'Groupes'], ['billing', 'Offres et paiements'], ['settings', 'Réglages']]
  return (
    <div className="mx-auto flex w-full max-w-[1080px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader title="Administration" subtitle="Membres, familles et associations, offres, paiements et réglages de Noon." />
      <div role="tablist" aria-label="Sections" className="flex gap-1 overflow-x-auto border-b border-line">
        {tabs.map(([k, l]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`-mb-px h-11 flex-none border-b-2 px-3.5 text-[14px] ${tab === k ? 'border-indigo font-semibold text-ink' : 'border-transparent font-medium text-muted'}`}>{l}</button>
        ))}
      </div>
      {tab === 'members' && <Members />}
      {tab === 'groups' && <Groups />}
      {tab === 'billing' && <BillingAdmin />}
      {tab === 'settings' && <SettingsAdmin />}
    </div>
  )
}

function Members() {
  const toast = useToast()
  const [q, setQ] = useState('')
  const [data, setData] = useState<{ members: User[]; roles: { key: string; label: string }[]; groups: Group[] } | null>(null)
  const [invite, setInvite] = useState<{ name: string; phone: string; email: string; role: string; group_id: string } | null>(null)
  const [code, setCode] = useState<{ name: string; code: string } | null>(null)

  const load = (term = q) => api.get<typeof data>('/admin/members' + (term ? '?q=' + encodeURIComponent(term) : '')).then(setData)
  useEffect(() => {
    load('')
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const update = async (u: User, patch: Record<string, unknown>) => {
    try {
      await api.put(`/admin/members/${u.id}`, patch)
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
      load()
    }
  }
  const send = async () => {
    if (!invite) return
    try {
      const res = await api.post<{ member: User; temporary_code: string }>('/admin/members', { ...invite, phone: invite.phone || null, email: invite.email || null, group_id: invite.group_id ? Number(invite.group_id) : null })
      setInvite(null)
      setCode({ name: res.member.name, code: res.temporary_code })
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  const reset = async (u: User) => {
    if (!window.confirm(`Générer un nouveau code pour ${u.name} ?`)) return
    try {
      const res = await api.post<{ temporary_code: string }>(`/admin/members/${u.id}/reset-code`)
      setCode({ name: u.name, code: res.temporary_code })
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }

  if (!data) return <Spinner />
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <form onSubmit={(e) => { e.preventDefault(); load() }} className="relative min-w-[240px] max-w-sm flex-1">
          <label htmlFor="m-q" className="sr-only-noon">Rechercher un membre</label>
          <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true" />
          <input id="m-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, téléphone ou e-mail" className={inputCls + ' h-10 pl-9'} />
        </form>
        <Button onClick={() => setInvite({ name: '', phone: '', email: '', role: 'member', group_id: '' })}><Plus size={16} />Inviter un membre</Button>
      </div>
      <div className="overflow-hidden rounded-[14px] border border-line bg-surface">
        {data.members.map((m) => (
          <div key={m.id} className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 last:border-b-0">
            <span className="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-sunken text-[13px] font-semibold text-ink-2">{m.initials}</span>
            <div className="flex min-w-[180px] flex-1 flex-col">
              <span className="text-[14px] font-semibold">{m.name} {!m.is_active && <Badge tone="danger">Désactivé</Badge>}</span>
              <span className="text-[12.5px] text-muted">{[m.phone, m.email].filter(Boolean).join(' · ')} · {m.language_name}</span>
            </div>
            <label className="flex"><span className="sr-only-noon">Groupe de {m.name}</span>
              <select value={m.group?.id ?? ''} onChange={(e) => update(m, { group_id: e.target.value ? Number(e.target.value) : null })} className="h-9 rounded-[8px] border border-line-strong bg-surface px-2 text-[13px]">
                <option value="">Sans groupe</option>
                {data.groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
              </select>
            </label>
            <label className="flex"><span className="sr-only-noon">Rôle de {m.name}</span>
              <select value={m.role} disabled={m.is_protected} onChange={(e) => update(m, { role: e.target.value })} className="h-9 rounded-[8px] border border-line-strong bg-surface px-2 text-[13px]">
                {data.roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
              </select>
            </label>
            {!m.is_protected && (
              <>
                <button type="button" aria-label={`Nouveau code pour ${m.name}`} title="Nouveau code" onClick={() => reset(m)} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><KeyRound size={16} /></button>
                <button type="button" onClick={() => update(m, { is_active: !m.is_active })} className="h-9 rounded-[8px] px-2 text-[13px] font-medium text-ink-2 hover:bg-sunken">{m.is_active ? 'Désactiver' : 'Réactiver'}</button>
              </>
            )}
          </div>
        ))}
      </div>

      <Modal open={!!invite} title="Inviter un membre" onClose={() => setInvite(null)} footer={<><Button variant="secondary" onClick={() => setInvite(null)}>Annuler</Button><Button onClick={send} disabled={!invite?.name || (!invite.phone && !invite.email)}>Créer le compte</Button></>}>
        {invite && (
          <div className="flex flex-col gap-4">
            <Field label="Nom"><input className={inputCls} value={invite.name} onChange={(e) => setInvite({ ...invite, name: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Téléphone"><input className={inputCls} inputMode="tel" value={invite.phone} onChange={(e) => setInvite({ ...invite, phone: e.target.value })} placeholder="+237…" /></Field>
              <Field label="ou e-mail"><input className={inputCls} type="email" value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} /></Field>
              <Field label="Rôle"><select className={inputCls} value={invite.role} onChange={(e) => setInvite({ ...invite, role: e.target.value })}>{data.roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}</select></Field>
              <Field label="Groupe"><select className={inputCls} value={invite.group_id} onChange={(e) => setInvite({ ...invite, group_id: e.target.value })}><option value="">Sans groupe</option>{data.groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}</select></Field>
            </div>
          </div>
        )}
      </Modal>
      <Modal open={!!code} title="Code provisoire" onClose={() => setCode(null)} footer={<Button onClick={() => setCode(null)}>C'est noté</Button>}>
        {code && (
          <div className="flex flex-col gap-3 text-[15px]">
            <p className="m-0">Transmettez ce code à <strong>{code.name}</strong>. Il ne sera plus affiché ensuite.</p>
            <p className="m-0 rounded-[12px] bg-sunken py-4 text-center font-mono text-[30px] font-bold tracking-[.2em]">{code.code}</p>
            <p className="m-0 text-[13px] text-muted">La personne pourra le changer dans ses réglages.</p>
          </div>
        )}
      </Modal>
    </section>
  )
}

function Groups() {
  const toast = useToast()
  const [data, setData] = useState<{ groups: Group[]; kinds: { key: string; label: string }[] } | null>(null)
  const [draft, setDraft] = useState<{ id?: number; name: string; kind: string; description: string; monthly_token_budget: string } | null>(null)
  const load = () => api.get<typeof data>('/admin/groups').then(setData)
  useEffect(() => {
    load()
  }, [])
  const save = async () => {
    if (!draft) return
    const body = { ...draft, monthly_token_budget: draft.monthly_token_budget ? Number(draft.monthly_token_budget) : null }
    try {
      if (draft.id) await api.put(`/admin/groups/${draft.id}`, body)
      else await api.post('/admin/groups', body)
      setDraft(null)
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  if (!data) return <Spinner />
  return (
    <section className="flex flex-col gap-3">
      <div className="flex justify-between gap-3">
        <p className="m-0 text-[14px] text-muted">Familles, villages et associations : pour regrouper les membres, partager une offre Famille et suivre la consommation.</p>
        <Button onClick={() => setDraft({ name: '', kind: 'famille', description: '', monthly_token_budget: '' })}><Plus size={16} />Nouveau groupe</Button>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {data.groups.map((g) => (
          <Card key={g.id} className="flex flex-col gap-2 p-4">
            <div className="flex items-center justify-between gap-2"><span className="text-[15px] font-semibold">{g.name}</span><Badge>{g.kind_label}</Badge></div>
            <span className="text-[13px] text-muted">{g.members} membre{g.members > 1 ? 's' : ''}{g.monthly_token_budget ? ` · budget ${new Intl.NumberFormat('fr-FR').format(g.monthly_token_budget)} tokens/mois` : ''}</span>
            {g.description && <span className="text-[13px] text-ink-2">{g.description}</span>}
            <div className="mt-1 flex gap-1.5">
              <Button size="sm" variant="secondary" onClick={() => setDraft({ id: g.id, name: g.name, kind: g.kind, description: g.description ?? '', monthly_token_budget: g.monthly_token_budget ? String(g.monthly_token_budget) : '' })}>Modifier</Button>
              <Button size="sm" variant="ghost" onClick={async () => { if (window.confirm(`Supprimer ${g.name} ?`)) { await api.del(`/admin/groups/${g.id}`); load() } }}><Trash2 size={14} /></Button>
            </div>
          </Card>
        ))}
      </div>
      <Modal open={!!draft} title={draft?.id ? 'Modifier le groupe' : 'Nouveau groupe'} onClose={() => setDraft(null)} footer={<><Button variant="secondary" onClick={() => setDraft(null)}>Annuler</Button><Button onClick={save} disabled={!draft?.name}>Enregistrer</Button></>}>
        {draft && (
          <div className="flex flex-col gap-4">
            <Field label="Nom"><input className={inputCls} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></Field>
            <Field label="Type"><select className={inputCls} value={draft.kind} onChange={(e) => setDraft({ ...draft, kind: e.target.value })}>{data.kinds.map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}</select></Field>
            <Field label="Description (facultatif)"><input className={inputCls} value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} /></Field>
            <Field label="Budget mensuel de tokens (facultatif)" hint="Une alerte s'affiche dans Consommation quand il est dépassé."><input className={inputCls} inputMode="numeric" value={draft.monthly_token_budget} onChange={(e) => setDraft({ ...draft, monthly_token_budget: e.target.value.replace(/\D/g, '') })} /></Field>
          </div>
        )}
      </Modal>
    </section>
  )
}

interface BillingAdminData {
  payments: Payment[]; plans: Plan[]
  revenue: { month_xaf: number; month_eur: number; active_subscriptions: number }
  settings: { momo_orange_number: string | null; momo_mtn_number: string | null; momo_merchant_name: string | null; sales_contact: string | null; card_enabled: boolean }
}

function BillingAdmin() {
  const toast = useToast()
  const [status, setStatus] = useState('pending')
  const [data, setData] = useState<BillingAdminData | null>(null)
  const [settings, setSettings] = useState<BillingAdminData['settings'] | null>(null)
  const [plan, setPlan] = useState<Plan | null>(null)

  const load = () => api.get<BillingAdminData>('/admin/billing?status=' + status).then((d) => {
    setData(d)
    setSettings(d.settings)
  })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [status])
  if (!data || !settings) return <Spinner />

  const act = async (p: Payment, verb: 'validate' | 'reject') => {
    if (verb === 'validate' && !window.confirm(`Confirmer la réception de ${p.currency === 'XAF' ? fcfa(p.amount) : eur(p.amount)} (réf. ${p.reference}) ?`)) return
    try {
      await api.post(`/admin/payments/${p.id}/${verb}`)
      toast(verb === 'validate' ? 'Paiement confirmé, offre activée.' : 'Paiement refusé.', verb === 'validate' ? 'success' : 'info')
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  const saveSettings = async () => {
    await api.put('/admin/billing/settings', settings)
    toast('Réglages enregistrés', 'success')
    load()
  }
  const savePlan = async () => {
    if (!plan) return
    try {
      await api.put(`/admin/plans/${plan.id}`, { name: plan.name, tagline: plan.tagline, price_xaf: plan.price_xaf, price_eur: plan.price_eur, daily_messages: plan.daily_messages, daily_voice: plan.daily_voice, premium_models: plan.premium_models, seats: plan.seats, features: plan.features })
      setPlan(null)
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }

  return (
    <section className="flex flex-col gap-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="flex flex-col gap-1 p-4"><span className="text-[13px] text-muted">Encaissé ce mois (Mobile Money)</span><span className="font-display text-[26px] font-semibold">{fcfa(data.revenue.month_xaf)}</span></Card>
        <Card className="flex flex-col gap-1 p-4"><span className="text-[13px] text-muted">Encaissé ce mois (carte)</span><span className="font-display text-[26px] font-semibold">{eur(data.revenue.month_eur)}</span></Card>
        <Card className="flex flex-col gap-1 p-4"><span className="text-[13px] text-muted">Abonnements actifs</span><span className="font-display text-[26px] font-semibold">{data.revenue.active_subscriptions}</span></Card>
      </div>

      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="m-0 text-[15px] font-semibold">Paiements</h2>
          <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filtrer les paiements" className="h-9 rounded-[8px] border border-line-strong bg-surface px-2 text-[13px]">
            <option value="pending">À confirmer</option><option value="paid">Payés</option><option value="failed">Refusés</option><option value="all">Tous</option>
          </select>
        </div>
        <div className="overflow-x-auto rounded-[14px] border border-line bg-surface">
          <table className="w-full min-w-[720px] border-collapse text-[14px]">
            <thead><tr className="bg-sunken text-left text-[12.5px] text-ink-2"><th className="px-4 py-2.5 font-semibold">Référence</th><th className="px-4 py-2.5 font-semibold">Personne</th><th className="px-4 py-2.5 font-semibold">Offre</th><th className="px-4 py-2.5 font-semibold">Montant</th><th className="px-4 py-2.5 font-semibold">Payeur</th><th className="px-4 py-2.5" /></tr></thead>
            <tbody>
              {data.payments.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Aucun paiement.</td></tr>}
              {data.payments.map((p) => (
                <tr key={p.id} className="border-t border-line">
                  <td className="px-4 py-2.5 font-mono text-[13px]">{p.reference}</td>
                  <td className="px-4 py-2.5">{p.user?.name}</td>
                  <td className="px-4 py-2.5">{p.plan} · {p.months} mois</td>
                  <td className="px-4 py-2.5">{p.currency === 'XAF' ? fcfa(p.amount) : eur(p.amount)}</td>
                  <td className="px-4 py-2.5 text-[13px] text-muted">{p.provider === 'stripe' ? 'Carte' : `${p.operator === 'orange' ? 'Orange' : 'MTN'} · ${p.phone}`}</td>
                  <td className="px-4 py-2.5 text-right">
                    {p.status === 'pending' && p.provider === 'manual' ? (
                      <span className="inline-flex gap-1.5"><Button size="sm" onClick={() => act(p, 'validate')}><Check size={14} />Reçu</Button><Button size="sm" variant="secondary" onClick={() => act(p, 'reject')}><X size={14} /></Button></span>
                    ) : <Badge tone={p.status === 'paid' ? 'success' : p.status === 'pending' ? 'warn' : 'neutral'}>{p.status === 'paid' ? 'Payé' : p.status === 'pending' ? 'En attente' : p.status === 'failed' ? 'Refusé' : 'Annulé'}</Badge>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <h2 className="m-0 text-[15px] font-semibold">Offres et prix</h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.plans.map((p) => (
            <Card key={p.id} className="flex flex-col gap-1.5 p-4">
              <span className="text-[15px] font-semibold">{p.name}</span>
              <span className="text-[13.5px] text-ink-2">{p.contact_sales ? 'Sur devis' : p.is_free ? 'Gratuit' : `${fcfa(p.price_xaf)} · ${eur(p.price_eur)} / mois`}</span>
              <span className="text-[12.5px] text-muted">{p.daily_messages === null ? 'Messages illimités' : `${p.daily_messages} messages/jour`} · {p.seats} personne{p.seats > 1 ? 's' : ''}{p.premium_models ? ' · meilleurs modèles' : ''}</span>
              <Button size="sm" variant="secondary" className="mt-1 self-start" onClick={() => setPlan({ ...p })}>Modifier</Button>
            </Card>
          ))}
        </div>
      </div>

      <Card className="flex flex-col gap-4 p-5">
        <h2 className="m-0 text-[15px] font-semibold">Encaissement</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Numéro Orange Money marchand"><input className={inputCls} value={settings.momo_orange_number ?? ''} onChange={(e) => setSettings({ ...settings, momo_orange_number: e.target.value })} /></Field>
          <Field label="Numéro MTN MoMo marchand"><input className={inputCls} value={settings.momo_mtn_number ?? ''} onChange={(e) => setSettings({ ...settings, momo_mtn_number: e.target.value })} /></Field>
          <Field label="Nom affiché du bénéficiaire"><input className={inputCls} value={settings.momo_merchant_name ?? ''} onChange={(e) => setSettings({ ...settings, momo_merchant_name: e.target.value })} /></Field>
          <Field label="Contact commercial (e-mail ou téléphone)"><input className={inputCls} value={settings.sales_contact ?? ''} onChange={(e) => setSettings({ ...settings, sales_contact: e.target.value })} /></Field>
        </div>
        <span className="text-[13px] text-muted">Carte bancaire (Stripe) : {settings.card_enabled ? 'activée' : 'désactivée — renseignez STRIPE_SECRET et STRIPE_WEBHOOK_SECRET dans le .env du serveur'}.</span>
        <Button className="self-start" onClick={saveSettings}>Enregistrer</Button>
      </Card>

      <Modal open={!!plan} title={`Offre ${plan?.name ?? ''}`} onClose={() => setPlan(null)} footer={<><Button variant="secondary" onClick={() => setPlan(null)}>Annuler</Button><Button onClick={savePlan}>Enregistrer</Button></>}>
        {plan && (
          <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nom"><input className={inputCls} value={plan.name} onChange={(e) => setPlan({ ...plan, name: e.target.value })} /></Field>
              <Field label="Accroche"><input className={inputCls} value={plan.tagline ?? ''} onChange={(e) => setPlan({ ...plan, tagline: e.target.value })} /></Field>
              <Field label="Prix mensuel (FCFA)"><input className={inputCls} inputMode="numeric" value={plan.price_xaf} onChange={(e) => setPlan({ ...plan, price_xaf: Number(e.target.value.replace(/\D/g, '')) || 0 })} /></Field>
              <Field label="Prix mensuel (€)"><input className={inputCls} inputMode="decimal" value={plan.price_eur} onChange={(e) => setPlan({ ...plan, price_eur: Number(e.target.value.replace(',', '.')) || 0 })} /></Field>
              <Field label="Messages par jour" hint="Vide = illimité"><input className={inputCls} inputMode="numeric" value={plan.daily_messages ?? ''} onChange={(e) => setPlan({ ...plan, daily_messages: e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null })} /></Field>
              <Field label="Voix en langue locale par jour" hint="Vide = illimité"><input className={inputCls} inputMode="numeric" value={plan.daily_voice ?? ''} onChange={(e) => setPlan({ ...plan, daily_voice: e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null })} /></Field>
              <Field label="Personnes couvertes"><input className={inputCls} inputMode="numeric" value={plan.seats} onChange={(e) => setPlan({ ...plan, seats: Number(e.target.value.replace(/\D/g, '')) || 1 })} /></Field>
            </div>
            <Toggle checked={plan.premium_models} onChange={(v) => setPlan({ ...plan, premium_models: v })} label="Accès aux meilleurs modèles (payants)" />
            <Field label="Avantages affichés" hint="Un par ligne"><textarea rows={4} className={inputCls + ' h-auto py-2'} value={plan.features.join('\n')} onChange={(e) => setPlan({ ...plan, features: e.target.value.split('\n').filter((l) => l.trim()) })} /></Field>
          </div>
        )}
      </Modal>
    </section>
  )
}

function SettingsAdmin() {
  const toast = useToast()
  const [s, setS] = useState<{ open_registration: boolean; retention_days: number | null } | null>(null)
  useEffect(() => {
    api.get<typeof s>('/admin/settings').then(setS)
  }, [])
  if (!s) return <Spinner />
  const save = async (patch: Partial<NonNullable<typeof s>>) => {
    const next = { ...s, ...patch }
    setS(next)
    try {
      await api.put('/admin/settings', next)
      toast('Réglage enregistré', 'success')
    } catch (e) {
      toast((e as Error).message, 'error')
    }
  }
  return (
    <section className="flex max-w-xl flex-col gap-4">
      <Toggle checked={s.open_registration} onChange={(v) => save({ open_registration: v })} label="Inscriptions ouvertes" description="Tout le monde peut créer un compte gratuit avec son numéro." />
      <Field label="Conservation des conversations" hint="Les conversations plus anciennes sont supprimées automatiquement chaque nuit.">
        <select className={inputCls} value={s.retention_days ?? ''} onChange={(e) => save({ retention_days: e.target.value ? Number(e.target.value) : null })}>
          <option value="">Sans limite</option><option value="90">3 mois</option><option value="180">6 mois</option><option value="365">1 an</option><option value="730">2 ans</option>
        </select>
      </Field>
    </section>
  )
}
