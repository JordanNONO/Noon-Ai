import { Pin, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Empty, inputCls, PageHeader, Spinner, Tile } from '../components/ui'
import { api } from '../lib/api'
import { groupByDate, relativeTime } from '../lib/app'
import type { ConversationSummary } from '../lib/types'

export default function Discussions() {
  const [search, setSearch] = useSearchParams()
  const [q, setQ] = useState(search.get('q') ?? '')
  const [list, setList] = useState<ConversationSummary[] | null>(null)

  useEffect(() => {
    const term = search.get('q') ?? ''
    setQ(term)
    setList(null)
    api.get<{ conversations: ConversationSummary[] }>('/conversations' + (term ? '?q=' + encodeURIComponent(term) : '')).then((r) => setList(r.conversations)).catch(() => setList([]))
  }, [search])

  return (
    <div className="mx-auto flex w-full max-w-[860px] flex-col gap-5 px-4 py-8 sm:px-6">
      <PageHeader title="Discussions" subtitle="Toutes vos conversations avec Noon et ses assistants." />
      <form role="search" onSubmit={(e) => { e.preventDefault(); setSearch(q ? { q } : {}) }} className="relative">
        <label htmlFor="disc-q" className="sr-only-noon">Rechercher dans les conversations</label>
        <Search size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true" />
        <input id="disc-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher un mot, un sujet…" className={inputCls + ' pl-10'} />
      </form>
      {list === null && <Spinner />}
      {list?.length === 0 && <Empty title={search.get('q') ? 'Aucun résultat' : 'Pas encore de conversation'}>{search.get('q') ? 'Essayez un autre mot.' : 'Posez votre première question à Noon depuis l’accueil.'}</Empty>}
      {list && groupByDate(list).map((g) => (
        <section key={g.label} className="flex flex-col gap-1">
          <h2 className="m-0 px-1 text-[12px] font-semibold uppercase tracking-[.04em] text-muted">{g.label}</h2>
          <div className="overflow-hidden rounded-[14px] border border-line bg-surface">
            {g.items.map((c) => (
              <Link key={c.id} to={`/c/${c.id}`} className="flex items-center gap-3 border-b border-line px-4 py-3 no-underline last:border-b-0 hover:bg-sunken">
                <Tile letter={c.agent?.initial ?? 'N'} hue={c.agent?.hue ?? 'indigo'} size={36} />
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="flex items-center gap-1.5 truncate text-[14.5px] font-semibold text-ink">{c.is_pinned && <Pin size={13} className="flex-none text-ochre" aria-label="Épinglée" />}{c.title}</span>
                  <span className="truncate text-[13px] text-muted">{c.snippet || '—'}</span>
                </span>
                <span className="flex-none text-[12px] text-muted">{relativeTime(c.last_message_at)}</span>
              </Link>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
