import { AlertTriangle, Download } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Button, Card, Empty, PageHeader, Spinner } from '../components/ui'
import { api } from '../lib/api'
import { formatTokens } from '../lib/app'

interface Data {
  tiles: { tokens: number; cost: number; active_users: number; groups: number; average: number }
  groups: { id: number; name: string; tokens: number; budget: number | null }[]
  days: { date: string; tokens: number }[]
  people: { id: number; name: string; initials: string; group: string; tokens: number; share: number; top_agent: string; conversations: number }[]
  alerts: { group: string; used: number; budget: number }[]
}
const PERIODS: [string, string][] = [['7j', '7 jours'], ['30j', '30 jours'], ['trimestre', 'Trimestre']]

export default function Usage() {
  const [period, setPeriod] = useState('30j')
  const [group, setGroup] = useState<number | null>(null)
  const [hover, setHover] = useState(-1)
  const [data, setData] = useState<Data | null>(null)

  useEffect(() => {
    setData(null)
    api.get<Data>(`/usage?period=${period}${group !== null ? `&group_id=${group}` : ''}`).then(setData)
  }, [period, group])

  const exportCsv = async () => {
    const blob = await api.blob(`/usage/export?period=${period}`)
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `noon-consommation-${period}.csv`
    a.click()
  }

  const label = PERIODS.find((p) => p[0] === period)![1].toLowerCase()
  const maxGroup = Math.max(1, ...(data?.groups.map((g) => g.tokens) ?? [1]))
  const maxDay = Math.max(1, ...(data?.days.map((d) => d.tokens) ?? [1]))
  const scopeName = group === null ? 'tous les groupes' : data?.groups.find((g) => g.id === group)?.name ?? ''
  const hovered = hover >= 0 && data ? data.days[hover] : null

  return (
    <div className="mx-auto flex w-full max-w-[1120px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title="Consommation"
        subtitle="Tokens utilisés par groupe et par personne, calculés à partir de l'usage réel."
        actions={
          <>
            <div role="group" aria-label="Période" className="flex gap-1 rounded-[10px] bg-sunken p-1">
              {PERIODS.map(([k, l]) => <button key={k} type="button" aria-pressed={period === k} onClick={() => setPeriod(k)} className={`h-8 rounded-[8px] px-3 text-[13px] ${period === k ? 'bg-surface font-semibold shadow-sm' : 'font-medium text-muted'}`}>{l}</button>)}
            </div>
            <Button variant="secondary" size="sm" onClick={exportCsv}><Download size={15} />Exporter CSV</Button>
          </>
        }
      />
      {!data && <Spinner />}
      {data && (
        <>
          {data.alerts.map((a) => (
            <div key={a.group} className="flex items-center gap-2.5 rounded-[12px] border border-warn/30 bg-warn-50 px-4 py-3 text-[14px] text-warn">
              <AlertTriangle size={18} /> {a.group} a dépassé son budget du mois : {formatTokens(a.used)} sur {formatTokens(a.budget)} tokens.
            </div>
          ))}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[['Tokens consommés', formatTokens(data.tiles.tokens), label], ['Coût estimé', new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(data.tiles.cost), 'modèles payants uniquement'], ['Personnes actives', String(data.tiles.active_users), `${data.tiles.groups} groupe${data.tiles.groups > 1 ? 's' : ''}`], ['Moyenne par personne', formatTokens(data.tiles.average), label]].map(([l, v, s]) => (
              <Card key={l} className="flex flex-col gap-1 p-4"><span className="text-[13px] text-muted">{l}</span><span className="font-display text-[28px] font-semibold leading-tight">{v}</span><span className="text-[12.5px] text-muted">{s}</span></Card>
            ))}
          </div>

          <div className="flex flex-wrap gap-4">
            <Card className="flex min-w-0 flex-[1_1_360px] flex-col gap-3 p-5">
              <div className="flex items-baseline justify-between gap-2"><h2 className="m-0 text-[15px] font-semibold">Par groupe</h2><span className="text-[12.5px] text-muted">Touchez un groupe pour filtrer</span></div>
              {data.groups.length === 0 && <span className="text-[13.5px] text-muted">Aucun groupe.</span>}
              {data.groups.map((g) => {
                const on = group === g.id
                return (
                  <button key={g.id} type="button" aria-pressed={on} onClick={() => setGroup(on ? null : g.id)} className={`flex min-h-[44px] items-center gap-3 rounded-[10px] border px-2.5 text-left ${on ? 'border-indigo/40 bg-indigo-50' : 'border-transparent hover:bg-sunken'}`}>
                    <span className="w-32 flex-none truncate text-[13.5px] font-medium">{g.name}</span>
                    <span className="h-3.5 flex-1 overflow-hidden rounded-[4px] bg-sunken"><span className="block h-full rounded-r-[4px]" style={{ width: `${(g.tokens / maxGroup) * 100}%`, background: group !== null && !on ? '#CFC8B6' : '#1F2E5C' }} /></span>
                    <span className="w-14 flex-none text-right text-[13px] tabular-nums">{formatTokens(g.tokens)}</span>
                  </button>
                )
              })}
            </Card>
            <Card className="flex min-w-0 flex-[2_1_480px] flex-col gap-3 p-5">
              <div className="flex items-baseline justify-between gap-2"><h2 className="m-0 text-[15px] font-semibold">Tokens par jour · {scopeName}</h2><span className="text-[12.5px] text-muted">{hovered ? `${new Date(hovered.date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} · ${formatTokens(hovered.tokens)} tokens` : 'Survolez une barre'}</span></div>
              <div className="flex h-[200px] items-end gap-[3px]" onMouseLeave={() => setHover(-1)}>
                {data.days.map((d, i) => (
                  <span key={d.date} onMouseEnter={() => setHover(i)} className="flex h-full flex-1 items-end" title={`${d.date} : ${d.tokens}`}>
                    <span className="w-full rounded-t-[4px]" style={{ height: `${Math.max(d.tokens ? 2 : 0, (d.tokens / maxDay) * 100)}%`, background: hover === i ? '#D9A441' : '#1F2E5C' }} />
                  </span>
                ))}
              </div>
              <div className="flex justify-between text-[12px] text-muted"><span>{new Date(data.days[0].date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}</span><span>Aujourd'hui</span></div>
            </Card>
          </div>

          <section className="flex flex-col gap-2">
            <div className="flex items-center justify-between"><h2 className="m-0 text-[15px] font-semibold">Personnes · {scopeName}</h2>{group !== null && <button type="button" onClick={() => setGroup(null)} className="text-[13px] font-semibold text-indigo">Voir tous les groupes</button>}</div>
            {data.people.length === 0 ? <Empty title="Aucune activité sur cette période" /> : (
              <div className="overflow-x-auto rounded-[14px] border border-line bg-surface">
                <table className="w-full min-w-[720px] border-collapse text-[14px]">
                  <thead><tr className="bg-sunken text-left text-[12.5px] text-ink-2">{['Personne', 'Groupe', 'Tokens', 'Part', 'Assistant le plus utilisé', 'Conversations'].map((h) => <th key={h} scope="col" className="px-4 py-2.5 font-semibold">{h}</th>)}</tr></thead>
                  <tbody>
                    {data.people.map((u) => (
                      <tr key={u.id} className="border-t border-line">
                        <th scope="row" className="px-4 py-2.5 text-left font-medium"><span className="flex items-center gap-2.5"><span className="flex h-7 w-7 items-center justify-center rounded-full bg-sunken text-[11.5px] font-semibold">{u.initials}</span>{u.name}</span></th>
                        <td className="px-4 py-2.5">{u.group}</td>
                        <td className="px-4 py-2.5 tabular-nums">{formatTokens(u.tokens)}</td>
                        <td className="px-4 py-2.5"><span className="flex items-center gap-2"><span className="h-1.5 w-16 overflow-hidden rounded-full bg-sunken"><span className="block h-full rounded-full bg-indigo" style={{ width: `${u.share}%` }} /></span><span className="text-[12.5px]">{u.share} %</span></span></td>
                        <td className="px-4 py-2.5">{u.top_agent}</td>
                        <td className="px-4 py-2.5 tabular-nums">{u.conversations}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}
