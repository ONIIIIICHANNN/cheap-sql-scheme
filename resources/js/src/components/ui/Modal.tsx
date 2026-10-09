import { X } from '@phosphor-icons/react'
import { AnimatePresence, motion } from 'motion/react'
import { createContext, useContext, useEffect, type CSSProperties, type ReactNode } from 'react'
import { cn } from '../../lib/cn'

type Size = 'md' | 'lg' | 'xl' | '2xl'

interface ModalProps {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  size?: Size
  header?: ReactNode
  /** Доп. классы полосы заголовка (например, разделитель снизу). */
  headerClassName?: string
  /**
   * Бумажный стикер (вид доски «на дереве»): стиль бумаги, скотч по углам и
   * анимация «прилипания» вместо обычного появления.
   */
  paper?: { style: CSSProperties }
}

/** Бумага для всех модалок внутри — так доска «на дереве» делает стикерами свои окна. */
// eslint-disable-next-line react-refresh/only-export-components
export const ModalPaper = createContext<{ style: CSSProperties } | null>(null)

const sizes: Record<Size, string> = {
  md: 'max-w-[460px]',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
  '2xl': 'max-w-6xl',
}

const TAPE: CSSProperties = {
  background: 'rgba(238,228,198,.62)',
  boxShadow: '0 1px 2px rgba(0,0,0,.25)',
  clipPath: 'polygon(3% 0,97% 6%,100% 30%,97% 55%,100% 80%,96% 100%,2% 94%,0 70%,3% 45%,0 20%)',
}

export function Modal({ open, title, onClose, children, footer, size = 'md', header, headerClassName, paper: own }: ModalProps) {
  const inherited = useContext(ModalPaper)
  const paper = own ?? inherited
  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.18 }}
          className="fixed inset-0 z-[60] grid place-items-center p-4 min-[600px]:p-6"
          style={{
            background: paper
              ? 'radial-gradient(ellipse at 50% 50%, rgba(20,10,4,.35), rgba(12,6,2,.72))'
              : 'var(--overlay)',
          }}
          onMouseDown={onClose}
          role="presentation"
        >
          <motion.div
            initial={paper ? { opacity: 0, y: 60, rotate: -7, scale: 0.55 } : { opacity: 0, y: 14, scale: 0.97 }}
            animate={paper ? { opacity: 1, y: 0, rotate: -0.6, scale: 1 } : { opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 8, scale: 0.98 }}
            transition={paper ? { duration: 0.5, ease: [0.2, 0.9, 0.3, 1] } : { duration: 0.26, ease: [0.2, 0.9, 0.3, 1] }}
            className={cn('relative w-full', sizes[size])}
            onMouseDown={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-label={title}
          >
            {paper && (
              <>
                <span aria-hidden className="absolute -top-3 -left-[18px] z-[3] h-7 w-[110px]" style={{ ...TAPE, transform: 'rotate(-38deg)' }} />
                <span aria-hidden className="absolute -top-3 -right-[18px] z-[3] h-7 w-[110px]" style={{ ...TAPE, transform: 'rotate(36deg)' }} />
              </>
            )}
            <div
              className={cn(
                'flex max-h-[calc(100vh-32px)] w-full flex-col bg-surface',
                paper ? '' : 'rounded-[24px] shadow-[var(--shadow-pop)]',
              )}
              style={
                paper
                  ? {
                      ...paper.style,
                      transform: 'none',
                      borderRadius: '3px 4px 34px 3px/3px 4px 12px 3px',
                      boxShadow: 'inset 0 0 40px rgba(110,70,20,.3),0 2px 4px rgba(0,0,0,.35),0 30px 60px -20px rgba(0,0,0,.7)',
                    }
                  : undefined
              }
            >
              <div className={cn('flex shrink-0 items-start justify-between gap-3 px-6 pt-6 pb-4', headerClassName)}>
                {header ?? (
                  <h2 className="truncate font-sans text-[20px] font-semibold tracking-normal">{title}</h2>
                )}
                <button
                  onClick={onClose}
                  aria-label="Закрыть"
                  className="-mt-2 -mr-2 grid size-11 shrink-0 place-items-center rounded-[10px] text-muted hover:bg-tint hover:text-fg"
                >
                  <X size={18} />
                </button>
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto px-6 pb-6">{children}</div>

              {footer && (
                <div className="flex shrink-0 flex-wrap justify-end gap-2.5 border-t border-divider px-6 py-4">
                  {footer}
                </div>
              )}
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
