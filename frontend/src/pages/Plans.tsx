import { Check, CreditCard, Smartphone } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge, Button, Card, Field, inputCls, Modal, PageHeader, Spinner, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Payment, Plan, Quota } from '../lib/types'

interface BillingData {
  plans: Plan[]
  quota: Quota
  payments: Payment[]
  methods: { card: boolean; mobile_money: Record<string, string>; merchant_name: string }
  sales_contact: string | null
}

interface Instructions { operator: string; number: string; merchant_name: string; amount: number; reference: string }

export const fcfa = (n: number) => new Intl.NumberFormat('fr-FR').format(n) + ' FCFA'
export const eur = (n: number) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(n)

const STATUS: Record<Payment['status'], [string, 'warn' | 'success' | 'danger' | 'neutral']> = {
  pending: ['En attente de confirmation', 'warn'],
  paid: ['Payé', 'success'],
  failed: ['Refusé', 'danger'],
  canceled: ['Annulé', 'neutral'],
}

export default function Plans() {
  const { refresh } = useApp()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const [data, setData] = useState<BillingData | null>(null)
  const [choice, setChoice] = useState<Plan | null>(null)
  const [method, setMethod] = useState<'mobile_money' | 'card'>('mobile_money')
  const [form, setForm] = useState({ months: 1, phone: '', operator: 'orange' })
  const [busy, setBusy] = useState(false)
  const [instr, setInstr] = useState<Instructions | null>(null)

  const load = () => api.get<BillingData>('/billing').then(setData)
  useEffect(() => {
    load()
  }, [])
  useEffect(() => {
    const p = params.get('paiement')
    if (p === 'ok') toast('Merci ! Votre paiement est confirmé.', 'success')
    if (p === 'annule') toast('Paiement annulé.')
    if (p) {
      setParams({}, { replace: true })
      refresh()
    }
  }, [params, setParams, toast, refresh])

  if (!data) return <Spinner />
  const momo = data.methods.mobile_money
  const ops = Object.keys(momo)
  const current = data.quota.plan.key

  const open = (p: Plan) => {
    setChoice(p)
    setInstr(null)
    setMethod(ops.length ? 'mobile_money' : 'card')
    setForm((f) => ({ ...f, operator: ops[0] ?? 'orange' }))
  }

  const pay = async () => {
    if (!choice) return
    setBusy(true)
    try {
      const res = await api.post<{ redirect?: string; instructions?: Instructions }>('/billing/checkout', {
        plan: choice.key, method, months: form.months,
        ...(method === 'mobile_money' ? { phone: form.phone, operator: form.operator } : {}),
      })
      if (res.redirect) {
        window.location.href = res.redirect
        return
      }
      setInstr(res.instructions ?? null)
      load()
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex w-full max-w-[1120px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader title="Offres" subtitle="Commencez gratuitement. Passez à une offre payante quand Noon vous rend service tous les jours." />

      <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="flex flex-col gap-0.5">
          <span className="text-[13px] text-muted">Votre offre actuelle</span>
          <span className="text-[17px] font-semibold">{data.quota.plan.name}</span>
          {data.quota.renews_at && <span className="text-[13px] text-muted">Active jusqu'au {new Date(data.quota.renews_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}{data.quota.is_owner ? '' : ' (offre de votre famille)'}</span>}
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge tone="indigo">{data.quota.remaining_today === null ? 'Messages illimités' : `${data.quota.remaining_today} message${data.quota.remaining_today > 1 ? 's' : ''} restant${data.quota.remaining_today > 1 ? 's' : ''} aujourd'hui`}</Badge>
          {data.quota.voice_remaining_today !== null && <Badge tone="ochre">{data.quota.voice_remaining_today} échange{data.quota.voice_remaining_today > 1 ? 's' : ''} vocal{data.quota.voice_remaining_today > 1 ? 'aux' : ''} en langue locale</Badge>}
        </div>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {data.plans.filter((p) => !p.contact_sales).map((p) => (
          <div key={p.id} className={`relative flex flex-col gap-4 rounded-[16px] border bg-surface p-5 ${p.is_highlighted ? 'border-indigo shadow-[0_0_0_3px_rgba(31,46,92,.12)]' : 'border-line'}`}>
            {p.is_highlighted && <span className="absolute -top-3 left-5 rounded-full bg-ochre-bright px-3 py-1 text-[12px] font-bold text-ink">Le plus choisi</span>}
            <div className="flex flex-col gap-1">
              <span className="text-[17px] font-semibold">{p.name}</span>
              <span className="text-[13.5px] text-muted">{p.tagline}</span>
            </div>
            <div className="flex flex-col">
              <span className="font-display text-[30px] font-semibold leading-none">{p.is_free ? 'Gratuit' : fcfa(p.price_xaf)}</span>
              {!p.is_free && <span className="mt-1 text-[13px] text-muted">par mois · ou {eur(p.price_eur)} par carte</span>}
            </div>
            <ul className="m-0 flex flex-1 list-none flex-col gap-2 p-0 text-[14px]">
              {p.features.map((f) => <li key={f} className="flex gap-2"><Check size={16} className="mt-0.5 flex-none text-success" aria-hidden="true" />{f}</li>)}
            </ul>
            {current === p.key ? (
              <Button variant="secondary" disabled>{p.is_free ? 'Votre offre' : 'Offre active'}</Button>
            ) : p.is_free ? (
              <span className="h-11" />
            ) : (
              <Button variant={p.is_highlighted ? 'primary' : 'secondary'} onClick={() => open(p)}>Choisir {p.name}</Button>
            )}
          </div>
        ))}
      </div>

      {data.plans.filter((p) => p.contact_sales).map((p) => (
        <Card key={p.id} className="flex flex-wrap items-center gap-5 p-5">
          <div className="flex min-w-[240px] flex-1 flex-col gap-1">
            <span className="text-[17px] font-semibold">{p.name}</span>
            <span className="text-[14px] text-muted">{p.tagline} — {p.features.join(' · ')}</span>
          </div>
          {data.sales_contact
            ? <a href={data.sales_contact.includes('@') ? `mailto:${data.sales_contact}` : `tel:${data.sales_contact}`} className="inline-flex h-11 items-center rounded-[10px] bg-indigo px-4 text-[14.5px] font-semibold text-surface no-underline">Nous contacter</a>
            : <span className="text-[13.5px] text-muted">Contact commercial : [À DÉFINIR par l'administrateur]</span>}
        </Card>
      ))}

      {data.payments.length > 0 && (
        <section className="flex flex-col gap-2">
          <h2 className="m-0 text-[15px] font-semibold">Mes paiements</h2>
          <div className="overflow-x-auto rounded-[14px] border border-line bg-surface">
            <table className="w-full min-w-[560px] border-collapse text-[14px]">
              <thead><tr className="bg-sunken text-left text-[12.5px] text-ink-2"><th className="px-4 py-2.5 font-semibold">Référence</th><th className="px-4 py-2.5 font-semibold">Offre</th><th className="px-4 py-2.5 font-semibold">Montant</th><th className="px-4 py-2.5 font-semibold">Statut</th></tr></thead>
              <tbody>
                {data.payments.map((p) => (
                  <tr key={p.id} className="border-t border-line">
                    <td className="px-4 py-2.5 font-mono text-[13px]">{p.reference}</td>
                    <td className="px-4 py-2.5">{p.plan} · {p.months} mois</td>
                    <td className="px-4 py-2.5">{p.currency === 'XAF' ? fcfa(p.amount) : eur(p.amount)}</td>
                    <td className="px-4 py-2.5"><Badge tone={STATUS[p.status][1]}>{STATUS[p.status][0]}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <Modal
        open={!!choice}
        title={instr ? 'Dernière étape : envoyez le paiement' : `Offre ${choice?.name ?? ''}`}
        onClose={() => setChoice(null)}
        footer={instr ? <Button onClick={() => setChoice(null)}>J'ai envoyé le paiement</Button> : (
          <>
            <Button variant="secondary" onClick={() => setChoice(null)}>Annuler</Button>
            <Button onClick={pay} disabled={busy || (method === 'mobile_money' && form.phone.trim().length < 8)}>{busy ? 'Un instant…' : method === 'card' ? 'Payer par carte' : 'Continuer'}</Button>
          </>
        )}
      >
        {choice && !instr && (
          <div className="flex flex-col gap-4">
            <div role="radiogroup" aria-label="Moyen de paiement" className="grid grid-cols-2 gap-2">
              <button type="button" role="radio" aria-checked={method === 'mobile_money'} disabled={!ops.length} onClick={() => setMethod('mobile_money')} className={`flex flex-col items-start gap-1 rounded-[12px] border p-3 text-left disabled:opacity-40 ${method === 'mobile_money' ? 'border-indigo bg-indigo-50' : 'border-line'}`}>
                <Smartphone size={18} /><span className="text-[14px] font-semibold">Mobile Money</span><span className="text-[12.5px] text-muted">Orange Money, MTN MoMo</span>
              </button>
              <button type="button" role="radio" aria-checked={method === 'card'} disabled={!data.methods.card} onClick={() => setMethod('card')} className={`flex flex-col items-start gap-1 rounded-[12px] border p-3 text-left disabled:opacity-40 ${method === 'card' ? 'border-indigo bg-indigo-50' : 'border-line'}`}>
                <CreditCard size={18} /><span className="text-[14px] font-semibold">Carte bancaire</span><span className="text-[12.5px] text-muted">Pour la diaspora</span>
              </button>
            </div>
            {!ops.length && !data.methods.card && <p className="m-0 rounded-[10px] bg-warn-50 px-3 py-2.5 text-[13.5px] text-warn">Aucun moyen de paiement n'est encore configuré. Un administrateur doit renseigner les numéros Mobile Money ou activer Stripe.</p>}
            <Field label="Durée">
              <select className={inputCls} value={form.months} onChange={(e) => setForm({ ...form, months: Number(e.target.value) })}>
                {[1, 3, 6, 12].map((m) => <option key={m} value={m}>{m} mois — {method === 'card' ? eur(choice.price_eur * m) : fcfa(choice.price_xaf * m)}</option>)}
              </select>
            </Field>
            {method === 'mobile_money' && ops.length > 0 && (
              <>
                <Field label="Opérateur">
                  <select className={inputCls} value={form.operator} onChange={(e) => setForm({ ...form, operator: e.target.value })}>
                    {ops.map((o) => <option key={o} value={o}>{o === 'orange' ? 'Orange Money' : 'MTN Mobile Money'}</option>)}
                  </select>
                </Field>
                <Field label="Numéro qui va payer" hint="Pour retrouver votre paiement.">
                  <input className={inputCls} inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="6 90 00 00 00" />
                </Field>
              </>
            )}
          </div>
        )}
        {instr && (
          <div className="flex flex-col gap-3 text-[15px]">
            <p className="m-0">Envoyez <strong>{fcfa(instr.amount)}</strong> par <strong>{instr.operator}</strong> au numéro :</p>
            <p className="m-0 rounded-[12px] bg-sunken px-4 py-3 text-center font-display text-[26px] font-semibold tracking-wide">{instr.number}</p>
            <p className="m-0 text-[14px] text-muted">Au nom de {instr.merchant_name}.</p>
            <p className="m-0">Indiquez cette référence dans le motif :</p>
            <p className="m-0 rounded-[12px] border-2 border-dashed border-ochre-bright px-4 py-3 text-center font-mono text-[22px] font-bold">{instr.reference}</p>
            <p className="m-0 text-[13.5px] text-muted">Votre offre s'active dès que nous avons confirmé la réception (en général dans l'heure). Vous la verrez ici.</p>
          </div>
        )}
      </Modal>
    </div>
  )
}
