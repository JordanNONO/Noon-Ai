import { Pencil, Play, Plus, Star } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, Empty, PageHeader, Spinner, Tile } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'
import type { Combo } from '../lib/types'

export default function Combos() {
  const { user, refresh } = useApp()
  const navigate = useNavigate()
  const [list, setList] = useState<Combo[] | null>(null)
  const load = () => api.get<{ combos: Combo[] }>('/combos').then((r) => setList(r.combos))
  useEffect(() => {
    load()
  }, [])

  const fav = async (c: Combo) => {
    await api.post(`/combos/${c.id}/favorite`)
    load()
    refresh()
  }

  return (
    <div className="mx-auto flex w-full max-w-[1000px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title="Parcours"
        subtitle="Plusieurs assistants qui travaillent l'un après l'autre : par exemple le programme des funérailles, puis le faire-part. Vous validez entre deux étapes si vous le souhaitez."
        actions={<Link to="/parcours/nouveau" className="inline-flex h-11 items-center gap-2 rounded-[10px] bg-indigo px-4 text-[14.5px] font-semibold text-surface no-underline"><Plus size={16} />Nouveau parcours</Link>}
      />
      {!list && <Spinner />}
      {list?.length === 0 && <Empty title="Aucun parcours">Créez votre premier parcours.</Empty>}
      <div className="flex flex-col gap-3">
        {list?.map((c) => (
          <article key={c.id} className="flex flex-wrap items-center gap-4 rounded-[14px] border border-line bg-surface p-4">
            <span className="flex">
              {c.steps.map((s, i) => s.agent && <span key={i} className={i ? '-ml-2' : ''}><Tile letter={s.agent.initial} hue={s.agent.hue} size={36} round className="ring-2 ring-surface" /></span>)}
            </span>
            <div className="flex min-w-[200px] flex-1 flex-col gap-0.5">
              <span className="text-[15.5px] font-semibold">{c.name}</span>
              <span className="text-[13px] text-muted">{c.meta}</span>
              <span className="text-[12.5px] text-muted">{c.steps.map((s) => s.task).join(' → ')}</span>
            </div>
            <div className="flex items-center gap-1.5">
              <button type="button" onClick={() => fav(c)} aria-pressed={c.is_favorite} aria-label={c.is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris'} className={`flex h-10 w-10 items-center justify-center rounded-[10px] hover:bg-sunken ${c.is_favorite ? 'text-ochre-bright' : 'text-muted'}`}><Star size={18} fill={c.is_favorite ? 'currentColor' : 'none'} /></button>
              {(user?.role === 'admin' || c.created_by === user?.id) && <Link to={`/parcours/${c.id}`} aria-label={`Modifier ${c.name}`} className="flex h-10 w-10 items-center justify-center rounded-[10px] text-muted hover:bg-sunken"><Pencil size={17} /></Link>}
              <Button onClick={() => navigate(`/?parcours=${c.id}`)}><Play size={12} fill="currentColor" />Lancer</Button>
            </div>
          </article>
        ))}
      </div>
    </div>
  )
}
