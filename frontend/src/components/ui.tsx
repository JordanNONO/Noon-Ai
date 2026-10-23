import { X } from 'lucide-react'
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react'
import type { Hue } from '../lib/types'

/* ---------- Couleurs des pastilles (assistants, connecteurs, modèles) ---------- */
const HUES: Record<string, [string, string]> = {
  indigo: ['#E8EBF4', '#1F2E5C'],
  ochre: ['#F6ECD8', '#7A4E0E'],
  clay: ['#F5E4DC', '#8A3A22'],
  stone: ['#EFEBE0', '#343A4F'],
  sage: ['#E4EBE0', '#2F4A33'],
}
export function hueColors(hue: Hue): [string, string] {
  return HUES[hue] ?? HUES.stone
}

export function Tile({ letter, hue, size = 32, round = false, className = '' }: { letter: string; hue: Hue; size?: number; round?: boolean; className?: string }) {
  const [bg, fg] = hueColors(hue)
  return (
    <span
      aria-hidden="true"
      className={'inline-flex flex-none items-center justify-center font-bold ' + className}
      style={{ width: size, height: size, borderRadius: round ? '50%' : Math.round(size / 4), background: bg, color: fg, fontSize: Math.round(size * 0.42) }}
    >
      {letter}
    </span>
  )
}

/* ---------- Logo : un N tissé, avec les points ocre du ndop ---------- */
export function LogoMark({ size = 28 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" role="img" aria-label="Noon IA">
      <rect width="64" height="64" rx="16" fill="#1F2E5C" />
      <path d="M20 46V18l24 28V18" fill="none" stroke="#FFFDF8" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="32" cy="10" r="3" fill="#D9A441" />
      <circle cx="32" cy="54" r="3" fill="#D9A441" />
    </svg>
  )
}

export function Logo({ size = 28 }: { size?: number }) {
  return (
    <span className="inline-flex items-center gap-2.5">
      <LogoMark size={size} />
      <span className="font-logo text-[19px] font-extrabold tracking-[-0.03em] text-indigo">
        Noon<span className="text-ochre"> IA</span>
      </span>
    </span>
  )
}

/* ---------- Motif ndop (décor, bandeaux) ---------- */
export function NdopBand({ className = '' }: { className?: string }) {
  return (
    <svg aria-hidden="true" className={className} width="100%" height="14" preserveAspectRatio="none">
      <defs>
        <pattern id="ndop" width="28" height="14" patternUnits="userSpaceOnUse">
          <rect width="28" height="14" fill="#1F2E5C" />
          <path d="M0 7 L7 1 L14 7 L7 13 Z M14 7 L21 1 L28 7 L21 13 Z" fill="none" stroke="#FFFDF8" strokeWidth="1.4" />
          <circle cx="7" cy="7" r="1.4" fill="#D9A441" />
          <circle cx="21" cy="7" r="1.4" fill="#FFFDF8" />
        </pattern>
      </defs>
      <rect width="100%" height="14" fill="url(#ndop)" />
    </svg>
  )
}

/* ---------- Boutons ---------- */
type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'ochre'
const VARIANTS: Record<Variant, string> = {
  primary: 'bg-indigo text-surface hover:bg-indigo-700 border border-indigo',
  secondary: 'bg-surface text-ink border border-line-strong hover:bg-sunken',
  ghost: 'bg-transparent text-ink-2 border border-transparent hover:bg-sunken',
  danger: 'bg-surface text-danger border border-line-strong hover:bg-danger-50',
  ochre: 'bg-ochre-bright text-ink border border-ochre-bright hover:brightness-95',
}

export function Button({ variant = 'primary', size = 'md', className = '', children, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: 'sm' | 'md' | 'lg' }) {
  const s = size === 'sm' ? 'h-9 px-3 text-[13.5px]' : size === 'lg' ? 'h-12 px-5 text-[15.5px]' : 'h-11 px-4 text-[14.5px]'
  return (
    <button
      {...rest}
      className={`inline-flex items-center justify-center gap-2 rounded-[10px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55 ${s} ${VARIANTS[variant]} ${className}`}
    >
      {children}
    </button>
  )
}

export function IconButton({ label, className = '', children, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return (
    <button
      {...rest}
      aria-label={label}
      title={label}
      className={`inline-flex h-10 w-10 flex-none items-center justify-center rounded-[10px] text-ink-2 transition-colors hover:bg-sunken disabled:opacity-50 ${className}`}
    >
      {children}
    </button>
  )
}

/* ---------- Badges ---------- */
type Tone = 'neutral' | 'success' | 'warn' | 'danger' | 'indigo' | 'ochre'
const TONES: Record<Tone, string> = {
  neutral: 'bg-sunken text-ink-2',
  success: 'bg-success-50 text-success',
  warn: 'bg-warn-50 text-warn',
  danger: 'bg-danger-50 text-danger',
  indigo: 'bg-indigo-50 text-indigo',
  ochre: 'bg-ochre-50 text-ochre',
}
export function Badge({ tone = 'neutral', children, className = '' }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-[3px] text-[12px] font-semibold ${TONES[tone]} ${className}`}>{children}</span>
}

/* ---------- Champs ---------- */
export function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-[13.5px] font-semibold text-ink">{label}</span>
      {children}
      {hint && !error && <span className="text-[12.5px] text-muted">{hint}</span>}
      {error && <span className="text-[12.5px] font-medium text-danger">{error}</span>}
    </label>
  )
}

export const inputCls = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[15px] text-ink outline-none focus:border-indigo'
export const textareaCls = 'w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[15px] leading-relaxed text-ink outline-none focus:border-indigo'

export function Toggle({ checked, onChange, label, description }: { checked: boolean; onChange: (v: boolean) => void; label: string; description?: string }) {
  return (
    <label className="flex min-h-[48px] cursor-pointer items-center justify-between gap-4 rounded-[10px] bg-sunken/60 px-3 py-2">
      <span className="flex flex-col">
        <span className="text-[14.5px] font-medium">{label}</span>
        {description && <span className="text-[12.5px] text-muted">{description}</span>}
      </span>
      <input type="checkbox" className="h-5 w-5 flex-none accent-indigo" checked={checked} onChange={(e) => onChange(e.target.checked)} />
    </label>
  )
}

/* ---------- En-tête de page ---------- */
export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 flex-col gap-1.5">
        <h1 className="m-0 font-display text-[28px] font-medium leading-tight sm:text-[32px]">{title}</h1>
        {subtitle && <p className="m-0 max-w-2xl text-[15px] text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function Card({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={`rounded-[14px] border border-line bg-surface ${className}`}>{children}</div>
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 rounded-[14px] border border-dashed border-line-strong px-6 py-10 text-center">
      <span className="text-[15px] font-semibold">{title}</span>
      {children && <span className="max-w-md text-[14px] text-muted">{children}</span>}
    </div>
  )
}

export function Spinner({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-10 text-[14px] text-muted">
      <span className="h-4 w-4 rounded-full border-2 border-line-strong border-t-indigo anim-spin" />
      {label}
    </div>
  )
}

/* ---------- Fenêtre modale ---------- */
export function Modal({ open, title, onClose, children, footer, wide = false }: { open: boolean; title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const d = ref.current
    if (!d) return
    if (open && !d.open) d.showModal()
    if (!open && d.open) d.close()
  }, [open])
  return (
    <dialog
      ref={ref}
      onClose={onClose}
      onCancel={onClose}
      className={`m-auto w-[calc(100%-32px)] ${wide ? 'max-w-2xl' : 'max-w-lg'} rounded-[16px] border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/40`}
    >
      {open && (
        <div className="flex max-h-[85vh] flex-col">
          <div className="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
            <h2 className="m-0 text-[17px] font-semibold">{title}</h2>
            <IconButton label="Fermer" onClick={onClose}><X size={18} /></IconButton>
          </div>
          <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
          {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
        </div>
      )}
    </dialog>
  )
}

/* ---------- Notifications ---------- */
interface ToastItem { id: number; text: string; tone: 'info' | 'error' | 'success' }
const ToastCtx = createContext<(text: string, tone?: ToastItem['tone']) => void>(() => {})

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([])
  const push = useCallback((text: string, tone: ToastItem['tone'] = 'info') => {
    const id = Date.now() + Math.random()
    setItems((l) => [...l, { id, text, tone }])
    setTimeout(() => setItems((l) => l.filter((t) => t.id !== id)), 5000)
  }, [])
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2 px-4 lg:bottom-6">
        {items.map((t) => (
          <div
            key={t.id}
            className={`anim-in pointer-events-auto max-w-md rounded-[12px] px-4 py-3 text-[14px] font-medium shadow-lg ${t.tone === 'error' ? 'bg-danger text-white' : t.tone === 'success' ? 'bg-success text-white' : 'bg-ink text-surface'}`}
          >
            {t.text}
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  )
}

export function useToast() {
  return useContext(ToastCtx)
}
