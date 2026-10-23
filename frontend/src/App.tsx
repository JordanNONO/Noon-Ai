import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import { Spinner } from './components/ui'
import { useApp } from './lib/app'
import ChatPage from './pages/ChatPage'
import Discussions from './pages/Discussions'
import Login from './pages/Login'

const Assistants = lazy(() => import('./pages/Assistants'))
const Combos = lazy(() => import('./pages/Combos'))
const ComboEditor = lazy(() => import('./pages/ComboEditor'))
const Knowledge = lazy(() => import('./pages/Knowledge'))
const Connectors = lazy(() => import('./pages/Connectors'))
const Models = lazy(() => import('./pages/Models'))
const Admin = lazy(() => import('./pages/Admin'))
const Usage = lazy(() => import('./pages/Usage'))
const Plans = lazy(() => import('./pages/Plans'))
const Settings = lazy(() => import('./pages/Settings'))
const Voice = lazy(() => import('./pages/Voice'))

export default function App() {
  const { ready, user } = useApp()
  if (!ready) return <Spinner label="Noon se prépare…" />
  if (!user) return <Login />

  return (
    <Suspense fallback={<Spinner />}>
      <Routes>
        <Route element={<Layout />}>
          <Route path="/" element={<ChatPage />} />
          <Route path="/c/:id" element={<ChatPage />} />
          <Route path="/discussions" element={<Discussions />} />
          <Route path="/assistants" element={<Assistants />} />
          <Route path="/parcours" element={<Combos />} />
          <Route path="/parcours/nouveau" element={<ComboEditor />} />
          <Route path="/parcours/:id" element={<ComboEditor />} />
          <Route path="/savoirs" element={<Knowledge />} />
          <Route path="/connecteurs" element={<Connectors />} />
          <Route path="/modeles" element={<Models />} />
          <Route path="/offres" element={<Plans />} />
          <Route path="/reglages" element={<Settings />} />
          <Route path="/vocal" element={<Voice />} />
          {user.role === 'admin' && <Route path="/admin" element={<Admin />} />}
          {user.role === 'admin' && <Route path="/consommation" element={<Usage />} />}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </Suspense>
  )
}
