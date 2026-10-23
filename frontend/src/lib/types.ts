export type Hue = 'indigo' | 'ochre' | 'clay' | 'stone' | string

export interface User {
  id: number
  name: string
  first_name: string
  short_name: string
  initials: string
  email: string | null
  phone: string | null
  role: 'admin' | 'moderator' | 'member'
  role_label: string
  can_moderate: boolean
  is_active: boolean
  is_protected: boolean
  language: string
  language_name: string
  bilingual_replies: boolean
  large_text: boolean
  group: { id: number; name: string } | null
  voice: VoicePrefs
}

export interface VoicePrefs {
  wake_word: boolean
  voice: string
  rate: number
  confirm_aloud: boolean
  auto_read: boolean
}

export interface Language {
  code: string
  name: string
  native_name: string | null
  family: string
  quality: string
  quality_label: string
  speech_to_text: boolean
  text_to_speech: boolean
  speech_engine: 'device' | 'service'
  approved_entries: number
}

export interface AgentLite {
  id: number
  name: string
  initial: string
  hue: Hue
}

export interface Agent extends AgentLite {
  slug: string
  theme: string
  theme_label: string
  description: string
  category: string
  starters: string[]
  is_default: boolean
  is_public: boolean
  connectors: { id: number; name: string; short: string }[]
  ai_model_id: number | null
  created_by: number | null
  author: string | null
  can_edit: boolean
  system_prompt?: string
}

export interface ConversationSummary {
  id: number
  title: string
  is_pinned: boolean
  language: string | null
  agent: AgentLite | null
  snippet: string
  last_message_at: string | null
}

export interface Step {
  id: string
  letter: string
  hue: Hue
  label: string
  status: string
  state: 'running' | 'done' | 'pending' | 'error'
  pending_id?: number
}

export interface PendingAction {
  id: number
  kind: 'tool' | 'combo_gate'
  tool: string | null
  summary: string
  payload: Record<string, unknown> | null
  status: 'pending' | 'approved' | 'rejected' | 'done' | 'failed'
  connector: string | null
  agent: string | null
}

export interface Message {
  id: number
  role: 'user' | 'assistant'
  agent: AgentLite | null
  content: string | null
  translation: string | null
  language: string | null
  audio_url: string | null
  steps: Step[]
  document: { title: string; body: string } | null
  sources: { id: number; title: string; type: string; language: string }[]
  attachments: { id: number; name: string; mime: string; size: number }[]
  model: string | null
  tokens: number
  status: 'streaming' | 'done' | 'error' | 'waiting'
  feedback: 'up' | 'down' | null
  pending_actions: PendingAction[]
  created_at: string | null
  notice?: string
}

export interface ConnectorLite {
  id: number
  name: string
  short: string
  letter: string
  hue: Hue
}

export interface ComboStep {
  id?: number
  position?: number
  agent: AgentLite | null
  agent_id: number
  task: string
  prompt: string
  model_tier: 'standard' | 'advanced'
  handoff: 'full' | 'summary' | 'files'
  requires_validation: boolean
  connector_ids: number[]
  connectors?: string[]
}

export interface Combo {
  id: number
  name: string
  trigger_type: 'manual' | 'schedule'
  schedule_cron: string | null
  schedule_label: string | null
  trigger_label: string
  is_favorite: boolean
  meta: string
  created_by: number | null
  last_run_at: string | null
  steps: ComboStep[]
}

export interface PlanInfo {
  key: string
  name: string
  daily_messages: number | null
  premium_models: boolean
  local_voice: boolean
  seats: number
}

export interface Quota {
  plan: PlanInfo
  used_today: number
  remaining_today: number | null
  voice_remaining_today: number | null
  renews_at: string | null
  is_owner: boolean
  status: 'active' | 'free'
}

export interface Plan {
  id: number
  key: string
  name: string
  tagline: string | null
  price_xaf: number
  price_eur: number
  daily_messages: number | null
  daily_voice: number | null
  premium_models: boolean
  seats: number
  features: string[]
  is_highlighted: boolean
  contact_sales: boolean
  is_free: boolean
}

export interface Payment {
  id: number
  reference: string
  plan: string | null
  provider: 'manual' | 'stripe'
  months: number
  amount: number
  currency: string
  phone: string | null
  operator: string | null
  status: 'pending' | 'paid' | 'failed' | 'canceled'
  user: { id: number; name: string; phone: string | null; email: string | null } | null
  paid_at: string | null
  created_at: string | null
}

export interface Bootstrap {
  user: User
  default_agent: Agent
  counts: { agents: number; combos: number; connectors_connected: number; knowledge_approved: number; knowledge_pending: number }
  connectors: ConnectorLite[]
  models: { id: number; name: string; family: string; label: string }[]
  languages: Language[]
  pinned: ConversationSummary[]
  recent: ConversationSummary[]
  favorite_combos: Combo[]
  speech: { online: boolean }
  quota?: Quota
}
