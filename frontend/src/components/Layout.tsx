import {
  BarChart3, BookOpen, Cpu, Crown, Home, LogOut, Menu, MessageCircle, Mic, Plug, Plus, Search, Settings, Shield, Sparkles, Workflow, X,
} from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { groupByDate, useApp } from '../lib/app'
import { Logo, Tile } from './ui'
import VoiceWidget from './VoiceWidget'

interface NavItem { to: string; label: string; icon: ReactNode; badge?: string | number; dot?: boolean; admin?: boolean }

function useNav(): NavItem[] {
  const { boot, user } = useApp()
  const c = boot?.counts
  const items: NavItem[] = [
    { to: '/discussions', label: 'Discussions', icon: <MessageCircle size={18} /> },
    { to: '/assistants', label: 'Assistants', icon: <Sparkles size={18} />, badge: c?.agents },
    { to: '/savoirs', label: 'Bibliothèque des savoirs', icon: <BookOpen size={18} />, badge: c?.knowledge_pending ? `${c.knowledge_pending} à valider` : undefined },
    { to: '/parcours', label: 'Parcours', icon: <Workflow size={18} />, badge: c?.combos },
    { to: '/connecteurs', label: 'Connecteurs', icon: <Plug size={18} />, dot: (c?.connectors_connected ?? 0) > 0 },
    { to: '/modeles', label: 'Modèles IA', icon: <Cpu size={18} /> },
    { to: '/offres', label: 'Offres', icon: <Crown size={18} /> },
    { to: '/admin', label: 'Administration', icon: <Shield size={18} />, admin: true },
    { to: '/consommation', label: 'Consommation', icon: <BarChart3 size={18} />, admin: true },
  ]
  return items.filter((i) => !i.admin || user?.role === 'admin')
}

const navCls = ({ isActive }: { isActive: boolean }) =>
  `flex min-h-[40px] items-center gap-2.5 rounded-[9px] px-2.5 text-[14px] no-underline ${isActive ? 'bg-indigo-50 font-semibold text-indigo' : 'font-medium text-ink-2 hover:bg-sunken'}`

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { boot, user, signOut } = useApp()
  const nav = useNav()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const recent = (boot?.recent ?? []).filter((c) => !c.is_pinned).slice(0, 12)

  return (
    <nav aria-label="Navigation principale" className="flex h-full min-h-0 flex-col gap-3.5 bg-surface px-3 pb-3 pt-3.5">
      <div className="flex items-center justify-between px-1.5">
        <Link to="/" onClick={onNavigate} className="no-underline"><Logo /></Link>
      </div>

      <div className="flex flex-col gap-2">
        <Link to="/" onClick={onNavigate} className="flex h-[42px] items-center gap-2.5 rounded-[10px] bg-indigo px-3 text-[14px] font-semibold text-surface no-underline hover:bg-indigo-700">
          <Plus size={16} strokeWidth={2.4} />
          <span className="flex-1">Nouvelle conversation</span>
        </Link>
        <form
          role="search"
          onSubmit={(e) => { e.preventDefault(); navigate('/discussions?q=' + encodeURIComponent(q)); onNavigate?.() }}
          className="flex h-[38px] items-center gap-2 rounded-[10px] border border-line px-2.5 text-muted"
        >
          <Search size={15} aria-hidden="true" />
          <label className="sr-only-noon" htmlFor="side-search">Rechercher</label>
          <input id="side-search" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher" className="min-w-0 flex-1 border-0 bg-transparent text-[14px] text-ink outline-none" />
        </form>
      </div>

      <div className="flex flex-col gap-px">
        {nav.map((i) => (
          <NavLink key={i.to} to={i.to} onClick={onNavigate} className={navCls}>
            {i.icon}
            <span className="flex-1">{i.label}</span>
            {i.badge !== undefined && i.badge !== 0 && <span className="text-[12px] text-muted">{i.badge}</span>}
            {i.dot && <span className="h-[7px] w-[7px] rounded-full bg-success" aria-label="Connectés" />}
          </NavLink>
        ))}
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-px overflow-y-auto">
        {(boot?.pinned.length ?? 0) > 0 && (
          <>
            <span className="px-2.5 pb-1 pt-1.5 text-[11.5px] font-semibold uppercase tracking-[.04em] text-muted">Épinglées</span>
            {boot!.pinned.map((c) => (
              <NavLink key={c.id} to={`/c/${c.id}`} onClick={onNavigate} className={({ isActive }) => `flex h-[34px] items-center gap-2.5 rounded-[8px] px-2.5 text-[13.5px] no-underline ${isActive ? 'bg-sunken text-ink' : 'text-ink-2 hover:bg-sunken'}`}>
                {c.agent && <Tile letter={c.agent.initial} hue={c.agent.hue} size={22} />}
                <span className="truncate">{c.title}</span>
              </NavLink>
            ))}
          </>
        )}
        {groupByDate(recent).map((g) => (
          <div key={g.label} className="flex flex-col gap-px">
            <span className="px-2.5 pb-1 pt-2 text-[11.5px] font-semibold uppercase tracking-[.04em] text-muted">{g.label}</span>
            {g.items.map((c) => (
              <NavLink key={c.id} to={`/c/${c.id}`} onClick={onNavigate} className={({ isActive }) => `block truncate rounded-[8px] px-2.5 py-[7px] text-[13.5px] no-underline ${isActive ? 'bg-sunken font-medium text-ink' : 'text-ink-2 hover:bg-sunken'}`}>
                {c.title}
              </NavLink>
            ))}
          </div>
        ))}
      </div>

      {user && (
        <div className="flex items-center gap-2.5 rounded-[12px] border border-line p-2">
          <span className="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-full bg-indigo text-[13px] font-semibold text-surface">{user.initials}</span>
          <Link to="/reglages" onClick={onNavigate} className="flex min-w-0 flex-1 flex-col no-underline">
            <span className="truncate text-[13.5px] font-semibold text-ink">{user.short_name}</span>
            <span className="truncate text-[12px] text-muted">{boot?.quota?.plan.name ?? user.role_label} · {user.language_name}</span>
          </Link>
          <Link to="/reglages" onClick={onNavigate} aria-label="Réglages" className="flex h-8 w-8 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><Settings size={16} /></Link>
          <button type="button" onClick={() => signOut()} aria-label="Se déconnecter" title="Se déconnecter" className="flex h-8 w-8 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><LogOut size={16} /></button>
        </div>
      )}
    </nav>
  )
}

function MobileTabBar({ onMenu }: { onMenu: () => void }) {
  const tab = ({ isActive }: { isActive: boolean }) => `flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-[11.5px] no-underline ${isActive ? 'font-semibold text-indigo' : 'text-muted'}`
  return (
    <nav aria-label="Navigation" className="fixed inset-x-0 bottom-0 z-30 flex items-stretch border-t border-line bg-surface/95 px-1 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
      <NavLink to="/" end className={tab}><Home size={21} />Accueil</NavLink>
      <NavLink to="/discussions" className={tab}><MessageCircle size={21} />Discussions</NavLink>
      <NavLink to="/vocal" className="flex flex-1 items-center justify-center no-underline" aria-label="Parler à Noon">
        <span className="-mt-5 flex h-14 w-14 items-center justify-center rounded-full bg-indigo text-surface shadow-lg ring-4 ring-ground"><Mic size={24} /></span>
      </NavLink>
      <NavLink to="/savoirs" className={tab}><BookOpen size={21} />Savoirs</NavLink>
      <button type="button" onClick={onMenu} className="flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-[11.5px] text-muted"><Menu size={21} />Menu</button>
    </nav>
  )
}

export default function Layout() {
  const [menu, setMenu] = useState(false)
  const loc = useLocation()
  const fullBleed = loc.pathname === '/vocal'
  useEffect(() => setMenu(false), [loc.pathname])

  if (fullBleed) return <Outlet />

  return (
    <div className="flex min-h-dvh">
      <aside className="sticky top-0 hidden h-dvh w-[272px] flex-none border-r border-line lg:block">
        <Sidebar />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col pb-[76px] lg:pb-0">
        <header className="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-line bg-surface/95 px-4 backdrop-blur lg:hidden">
          <Link to="/" className="no-underline"><Logo size={26} /></Link>
          <Link to="/" aria-label="Nouvelle conversation" className="flex h-10 w-10 items-center justify-center rounded-[10px] bg-indigo text-surface"><Plus size={18} /></Link>
        </header>
        <main className="flex min-h-0 flex-1 flex-col">
          <Outlet />
        </main>
      </div>

      <MobileTabBar onMenu={() => setMenu(true)} />
      {menu && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
          <button type="button" aria-label="Fermer le menu" className="absolute inset-0 bg-ink/40" onClick={() => setMenu(false)} />
          <div className="anim-in absolute inset-y-0 left-0 flex w-[86%] max-w-[320px] flex-col bg-surface shadow-xl">
            <button type="button" onClick={() => setMenu(false)} aria-label="Fermer" className="absolute right-2 top-2 z-10 flex h-10 w-10 items-center justify-center rounded-[10px] text-muted"><X size={20} /></button>
            <Sidebar onNavigate={() => setMenu(false)} />
          </div>
        </div>
      )}
      <VoiceWidget />
    </div>
  )
}
