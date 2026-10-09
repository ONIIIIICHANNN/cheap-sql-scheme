import { useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react'
import { cn } from '../../lib/cn'

interface ConfirmDeleteButtonProps {
  onConfirm: () => void
  /** Что показано до первого нажатия. */
  children?: ReactNode
  confirmLabel?: string
  title?: string
  className?: string
  /** Классы для состояния «Точно удалить?». */
  armedClassName?: string
}

const ARMED_MS = 4000

/**
 * Удаление в два нажатия, без модалки: первое взводит («Точно удалить?»),
 * второе в течение четырёх секунд — удаляет. Иначе остывает само.
 */
export function ConfirmDeleteButton({
  onConfirm,
  children = 'Удалить',
  confirmLabel = 'Точно удалить?',
  title = 'Удалить',
  className,
  armedClassName = 'text-warm',
}: ConfirmDeleteButtonProps) {
  const [armed, setArmed] = useState(false)
  const timer = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearTimeout(timer.current), [])

  useEffect(() => {
    if (!armed) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setArmed(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [armed])

  function handleClick(e: MouseEvent) {
    // Кнопка часто живёт внутри ссылки-карточки — не даём ей сработать.
    e.preventDefault()
    e.stopPropagation()
    window.clearTimeout(timer.current)
    if (!armed) {
      setArmed(true)
      timer.current = window.setTimeout(() => setArmed(false), ARMED_MS)
      return
    }
    setArmed(false)
    onConfirm()
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      title={armed ? 'Нажмите ещё раз, чтобы удалить' : title}
      aria-label={armed ? confirmLabel : title}
      className={cn(className, armed && armedClassName)}
    >
      {armed ? confirmLabel : children}
    </button>
  )
}
