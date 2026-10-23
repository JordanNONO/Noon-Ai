import { Check, Mic, Pause, Play, Plus, Search, Square, Trash2, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge, Button, Empty, Field, inputCls, Modal, PageHeader, Spinner, textareaCls, useToast } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../lib/app'

interface Entry {
  id: number; type: string; type_label: string; language: string; language_name: string; title: string; content: string
  translation: string | null; meaning: string | null; pronunciation: string | null; village: string | null; audio_url: string | null
  status: 'pending' | 'approved' | 'rejected'; author: string | null; author_id: number | null; reviewed_by: string | null; review_note: string | null; uses: number
}
interface Data { entries: Entry[]; types: { key: string; label: string; count: number }[]; counts: { approved: number; pending: number; mine: number }; can_moderate: boolean }

const blank = { type: 'mot', language: 'fr', title: '', content: '', translation: '', meaning: '', pronunciation: '', village: '' }

function AudioButton({ url }: { url: string }) {
  const [playing, setPlaying] = useState(false)
  const ref = useRef<HTMLAudioElement | null>(null)
  const toggle = async () => {
    if (playing) {
      ref.current?.pause()
      setPlaying(false)
      return
    }
    const blob = await api.blob(url.replace(/^.*\/api/, ''))
    const a = new Audio(URL.createObjectURL(blob))
    a.onended = () => setPlaying(false)
    ref.current = a
    await a.play()
    setPlaying(true)
  }
  return (
    <button type="button" onClick={toggle} className="inline-flex h-9 items-center gap-1.5 rounded-full bg-indigo-50 px-3 text-[13px] font-semibold text-indigo">
      {playing ? <Pause size={14} /> : <Play size={14} />} {playing ? 'Pause' : 'Écouter'}
    </button>
  )
}

function Recorder({ onBlob }: { onBlob: (b: Blob | null) => void }) {
  const [rec, setRec] = useState<MediaRecorder | null>(null)
  const [url, setUrl] = useState<string | null>(null)
  const toast = useToast()
  const start = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const r = new MediaRecorder(stream)
      const chunks: Blob[] = []
      r.ondataavailable = (e) => e.data.size && chunks.push(e.data)
      r.onstop = () => {
        stream.getTracks().forEach((t) => t.stop())
        const blob = new Blob(chunks, { type: r.mimeType || 'audio/webm' })
        setUrl(URL.createObjectURL(blob))
        onBlob(blob)
        setRec(null)
      }
      r.start()
      setRec(r)
    } catch {
      toast('Autorisez le micro pour enregistrer.', 'error')
    }
  }
  return (
    <div className="flex flex-wrap items-center gap-2">
      {rec ? (
        <Button type="button" variant="ochre" onClick={() => rec.stop()}><Square size={14} fill="currentColor" />Arrêter l'enregistrement</Button>
      ) : (
        <Button type="button" variant="secondary" onClick={start}><Mic size={16} />{url ? 'Recommencer' : 'Enregistrer la prononciation'}</Button>
      )}
      {url && !rec && (
        <>
          <audio controls src={url} className="h-10 max-w-full" />
          <button type="button" aria-label="Supprimer l'enregistrement" onClick={() => { setUrl(null); onBlob(null) }} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><X size={16} /></button>
        </>
      )}
    </div>
  )
}

export default function Knowledge() {
  const { boot, refresh } = useApp()
  const toast = useToast()
  const [params] = useSearchParams()
  const [tab, setTab] = useState<'approved' | 'pending' | 'mine'>('approved')
  const [type, setType] = useState('')
  const [lang, setLang] = useState('')
  const [q, setQ] = useState('')
  const [data, setData] = useState<Data | null>(null)
  const [draft, setDraft] = useState<typeof blank | null>(null)
  const [audio, setAudio] = useState<Blob | null>(null)
  const [busy, setBusy] = useState(false)
  const highlight = Number(params.get('id')) || null

  const load = () => {
    const qs = new URLSearchParams({ status: tab, ...(type && { type }), ...(lang && { language: lang }), ...(q && { q }) })
    api.get<Data>('/knowledge?' + qs).then(setData)
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [tab, type, lang])
  useEffect(() => {
    if (highlight) document.getElementById(`savoir-${highlight}`)?.scrollIntoView({ block: 'center' })
  }, [data, highlight])

  const save = async () => {
    if (!draft) return
    setBusy(true)
    const form = new FormData()
    Object.entries(draft).forEach(([k, v]) => v && form.append(k, v))
    if (audio) form.append('audio', audio, 'prononciation.webm')
    try {
      const res = await api.upload<{ entry: Entry }>('/knowledge', form)
      toast(res.entry.status === 'approved' ? 'Savoir publié. Merci !' : 'Merci ! Un modérateur va vérifier votre savoir.', 'success')
      setDraft(null)
      setAudio(null)
      load()
      refresh()
    } catch (e) {
      toast((e as Error).message, 'error')
    } finally {
      setBusy(false)
    }
  }

  const review = async (e: Entry, status: 'approved' | 'rejected') => {
    const note = status === 'rejected' ? window.prompt('Pourquoi ? (facultatif, visible par l’auteur)') ?? undefined : undefined
    await api.post(`/knowledge/${e.id}/review`, { status, note })
    load()
    refresh()
  }
  const remove = async (e: Entry) => {
    if (!window.confirm(`Supprimer « ${e.title} » ?`)) return
    await api.del(`/knowledge/${e.id}`)
    load()
  }

  const tabs: [typeof tab, string, number | undefined][] = [
    ['approved', 'Bibliothèque', data?.counts.approved],
    ...(data?.can_moderate ? [['pending', 'À valider', data.counts.pending] as [typeof tab, string, number]] : []),
    ['mine', 'Mes contributions', data?.counts.mine],
  ]

  return (
    <div className="mx-auto flex w-full max-w-[1080px] flex-col gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title="Bibliothèque des savoirs"
        subtitle="Mots, proverbes, contes, recettes, coutumes : ce que vous partagez ici, vérifié par les anciens, apprend à Noon à mieux parler nos langues."
        actions={<Button onClick={() => setDraft({ ...blank, language: boot?.user.language ?? 'fr' })}><Plus size={16} />Ajouter un savoir</Button>}
      />

      <div role="tablist" aria-label="Sections" className="flex gap-1 border-b border-line">
        {tabs.map(([k, l, n]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`-mb-px h-11 border-b-2 px-3.5 text-[14px] ${tab === k ? 'border-indigo font-semibold text-ink' : 'border-transparent font-medium text-muted'}`}>
            {l}{n ? <span className="ml-1.5 text-[12px] text-muted">{n}</span> : null}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <form onSubmit={(e) => { e.preventDefault(); load() }} className="relative min-w-[220px] flex-1">
          <label htmlFor="k-q" className="sr-only-noon">Rechercher</label>
          <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true" />
          <input id="k-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher un mot, un proverbe…" className={inputCls + ' pl-9'} />
        </form>
        <label className="flex"><span className="sr-only-noon">Type</span>
          <select className={inputCls + ' w-auto'} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">Tous les types</option>
            {data?.types.map((t) => <option key={t.key} value={t.key}>{t.label}{t.count ? ` (${t.count})` : ''}</option>)}
          </select>
        </label>
        <label className="flex"><span className="sr-only-noon">Langue</span>
          <select className={inputCls + ' w-auto'} value={lang} onChange={(e) => setLang(e.target.value)}>
            <option value="">Toutes les langues</option>
            {boot?.languages.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}
          </select>
        </label>
      </div>

      {!data && <Spinner />}
      {data?.entries.length === 0 && (
        <Empty title={tab === 'pending' ? 'Rien à valider' : 'Aucun savoir ici pour l’instant'}>
          {tab === 'approved' ? 'Soyez le premier à partager un mot, un proverbe ou une recette dans votre langue.' : ''}
        </Empty>
      )}

      <div className="grid gap-3 md:grid-cols-2">
        {data?.entries.map((e) => (
          <article id={`savoir-${e.id}`} key={e.id} className={`flex flex-col gap-2.5 rounded-[14px] border bg-surface p-4 ${highlight === e.id ? 'border-ochre-bright ring-2 ring-ochre-bright/40' : 'border-line'}`}>
            <div className="flex flex-wrap items-center gap-1.5">
              <Badge tone="ochre">{e.type_label}</Badge>
              <Badge>{e.language_name}</Badge>
              {e.village && <Badge>{e.village}</Badge>}
              {e.status === 'pending' && <Badge tone="warn">En attente</Badge>}
              {e.status === 'rejected' && <Badge tone="danger">Refusé</Badge>}
            </div>
            <h3 className="m-0 text-[16px] font-semibold">{e.title}</h3>
            <p className="m-0 whitespace-pre-line text-[15px] leading-relaxed">{e.content}</p>
            {e.pronunciation && <p className="m-0 text-[13.5px] text-muted">Prononciation : {e.pronunciation}</p>}
            {e.translation && <p className="m-0 whitespace-pre-line rounded-[10px] bg-sunken px-3 py-2 text-[14px] text-ink-2"><span className="font-semibold">Traduction : </span>{e.translation}</p>}
            {e.meaning && <p className="m-0 whitespace-pre-line text-[14px] text-ink-2"><span className="font-semibold">Sens : </span>{e.meaning}</p>}
            {e.review_note && <p className="m-0 text-[13px] text-danger">Note du modérateur : {e.review_note}</p>}
            <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-line pt-2.5">
              {e.audio_url && <AudioButton url={e.audio_url} />}
              <span className="text-[12px] text-muted">{e.author ? `Partagé par ${e.author}` : ''}{e.uses ? ` · utilisé ${e.uses} fois par Noon` : ''}</span>
              <span className="flex-1" />
              {tab === 'pending' && (
                <>
                  <Button size="sm" onClick={() => review(e, 'approved')}><Check size={14} />Valider</Button>
                  <Button size="sm" variant="secondary" onClick={() => review(e, 'rejected')}>Refuser</Button>
                </>
              )}
              {(data.can_moderate || (tab === 'mine' && e.status !== 'approved')) && (
                <button type="button" aria-label={`Supprimer ${e.title}`} onClick={() => remove(e)} className="flex h-9 w-9 items-center justify-center rounded-[8px] text-muted hover:bg-sunken"><Trash2 size={15} /></button>
              )}
            </div>
          </article>
        ))}
      </div>

      <Modal open={!!draft} wide title="Ajouter un savoir" onClose={() => setDraft(null)}
        footer={<><Button variant="secondary" onClick={() => setDraft(null)}>Annuler</Button><Button onClick={save} disabled={busy || !draft?.title || !draft.content}>{busy ? 'Envoi…' : 'Envoyer'}</Button></>}>
        {draft && (
          <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Type">
                <select className={inputCls} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>{data?.types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}</select>
              </Field>
              <Field label="Langue du texte">
                <select className={inputCls} value={draft.language} onChange={(e) => setDraft({ ...draft, language: e.target.value })}>{boot?.languages.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}</select>
              </Field>
            </div>
            <Field label="Titre" hint="ex. « Dire merci », « Proverbe sur la patience », « Sauce jaune de ma mère »">
              <input className={inputCls} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} maxLength={190} />
            </Field>
            <Field label="Texte original"><textarea rows={4} className={textareaCls} value={draft.content} onChange={(e) => setDraft({ ...draft, content: e.target.value })} /></Field>
            <Field label="Traduction en français"><textarea rows={3} className={textareaCls} value={draft.translation} onChange={(e) => setDraft({ ...draft, translation: e.target.value })} /></Field>
            <Field label="Sens, explication, quand l'utiliser"><textarea rows={3} className={textareaCls} value={draft.meaning} onChange={(e) => setDraft({ ...draft, meaning: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Prononciation (facultatif)"><input className={inputCls} value={draft.pronunciation} onChange={(e) => setDraft({ ...draft, pronunciation: e.target.value })} /></Field>
              <Field label="Village ou région (facultatif)"><input className={inputCls} value={draft.village} onChange={(e) => setDraft({ ...draft, village: e.target.value })} placeholder="ex. Bangangté" /></Field>
            </div>
            <div className="flex flex-col gap-1.5">
              <span className="text-[13.5px] font-semibold">Votre voix (facultatif mais précieux)</span>
              <span className="text-[12.5px] text-muted">Les enregistrements aident Noon à apprendre à écouter et à parler votre langue.</span>
              <Recorder onBlob={setAudio} />
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
