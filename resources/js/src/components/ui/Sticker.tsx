import { useEffect, useState, type CSSProperties, type ReactNode } from 'react'
import { cn } from '../../lib/cn'

interface StickerProps {
  /** Ключ data-stk — по нему slap() находит наклейку. */
  k: string
  /** Наклон в градусах; он же — конечный угол шлепка. */
  rot?: number
  /** Янтарная заливка — для редкой большой победы. */
  lamp?: boolean
  className?: string
  style?: CSSProperties
  children: ReactNode
}

/** Комиксовая наклейка победы: Caveat на розовом, не больше одной на блок. */
export function Sticker({ k, rot = 5, lamp, className, style, children }: StickerProps) {
  return (
    <span
      data-stk={k}
      data-rot={rot}
      className={cn('ax-sticker', lamp && 'ax-sticker-lamp', className)}
      style={{ transform: `rotate(${rot}deg)`, ...style }}
    >
      {children}
    </span>
  )
}

interface HandNoteProps {
  rot?: number
  size?: number
  className?: string
  children: ReactNode
}

/** Пометка на полях: прогресс, «ещё одна — и день закрыт». */
export function HandNote({ rot = -2, size = 22, className, children }: HandNoteProps) {
  return (
    <span className={cn('ax-hand', className)} style={{ transform: `rotate(${rot}deg)`, fontSize: size }}>
      {children}
    </span>
  )
}

/**
 * Перерисовка раз в 20 секунд, пока на экране есть наклейки «новая!» — чтобы
 * через 10 минут они сами исчезли.
 */
// eslint-disable-next-line react-refresh/only-export-components
export function useFreshTick(active: boolean): void {
  const [, setTick] = useState(0)
  useEffect(() => {
    if (!active) return
    const t = setInterval(() => setTick((n) => n + 1), 20_000)
    return () => clearInterval(t)
  }, [active])
}
