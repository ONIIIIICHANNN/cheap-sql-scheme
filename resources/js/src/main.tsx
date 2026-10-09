import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ToastProvider } from './components/ui/Toast'
import { config } from './lib/config'
import { SqlSchemaPage } from './pages/SqlSchemaPage'
import { SqlSchemasPage } from './pages/SqlSchemasPage'
import { ThemeProvider } from './theme/ThemeContext'
import './index.css'

/** Список схем — с полями, редактор — на весь экран. */
function App() {
  return (
    <Routes>
      <Route
        path="/"
        element={
          <main className="mx-auto w-full max-w-[1200px] px-4 py-8 min-[900px]:px-8">
            <SqlSchemasPage />
          </main>
        }
      />
      <Route
        path="/:id"
        element={
          <div className="h-screen">
            <SqlSchemaPage />
          </div>
        }
      />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

createRoot(document.getElementById('cheap-sql-scheme')!).render(
  <StrictMode>
    <ThemeProvider>
      <ToastProvider>
        <BrowserRouter basename={config.basePath}>
          <App />
        </BrowserRouter>
      </ToastProvider>
    </ThemeProvider>
  </StrictMode>,
)
