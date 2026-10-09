import { X } from '@phosphor-icons/react'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'

interface ToastState {
  id: number
  text: string
  undo: (() => void) | null
}

interface ToastContextValue {
  /** Показать тост. С undo появляется «Вернуть», и работает Ctrl+Z. */
  notify: (text: string, undo?: () => void) => void
  dismiss: () => void
}

const ToastContext = createContext<ToastContextValue | null>(null)

const LIFETIME_MS = 6000

/**
 * Один инвертированный тост внизу по центру (v3), на телефоне — над нижней панелью. Новый вытесняет старый:
 * отменить можно только последнее действие, и это честно.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<ToastState | null>(null)
  const timer = useRef<number | undefined>(undefined)

  const dismiss = useCallback(() => {
    window.clearTimeout(timer.current)
    setToast(null)
  }, [])

  const notify = useCallback((text: string, undo?: () => void) => {
    window.clearTimeout(timer.current)
    setToast({ id: Date.now(), text, undo: undo ?? null })
    timer.current = window.setTimeout(() => setToast(null), LIFETIME_MS)
  }, [])

  const runUndo = useCallback(() => {
    const undo = toast?.undo
    dismiss()
    undo?.()
  }, [toast, dismiss])

  useEffect(() => {
    if (!toast) return
    function onKey(e: KeyboardEvent) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'я') && toast?.undo) {
        // В полях ввода Ctrl+Z — это отмена набора, её не перехватываем.
        const tag = (e.target as HTMLElement | null)?.tagName
        if (tag === 'INPUT' || tag === 'TEXTAREA') return
        e.preventDefault()
        runUndo()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [toast, runUndo])

  useEffect(() => () => window.clearTimeout(timer.current), [])

  const value = useMemo(() => ({ notify, dismiss }), [notify, dismiss])

  return (
    <ToastContext value={value}>
      {children}
      {toast && (
        <div
          key={toast.id}
          role="status"
          aria-live="polite"
          className="fixed bottom-[88px] left-1/2 z-[90] flex min-h-[52px] max-w-[calc(100vw-32px)] items-center gap-3.5 rounded-[14px] bg-fg py-2.5 pr-2.5 pl-[18px] text-bg shadow-[0_12px_30px_rgba(0,0,0,.3)] [animation:ax-toast-in_.22s_cubic-bezier(.2,.9,.3,1)_both] min-[900px]:bottom-6"
          style={{ transform: 'translateX(-50%)' }}
        >
          <span className="text-[15px]">{toast.text}</span>
          {toast.undo && (
            <button
              onClick={runUndo}
              className="min-h-11 rounded-[10px] bg-lamp px-3.5 text-[15px] font-semibold text-[#1B1E27]"
            >
              Вернуть
            </button>
          )}
          <button
            onClick={dismiss}
            title="Скрыть"
            aria-label="Скрыть"
            className="grid size-[38px] shrink-0 place-items-center rounded-[10px] opacity-70 hover:opacity-100"
          >
            <X size={16} />
          </button>
        </div>
      )}
    </ToastContext>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext)
  if (!ctx) {
    throw new Error('useToast должен использоваться внутри <ToastProvider>')
  }
  return ctx
}
