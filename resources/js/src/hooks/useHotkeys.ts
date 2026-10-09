import { useEffect, useRef } from 'react'

type Handler = (e: KeyboardEvent) => void

/**
 * Одиночные клавиши страницы: «n» — создать, «/» — к поиску, Esc — закрыть.
 * Буквы ловятся и в русской раскладке (передайте обе: 'n', 'т').
 * Пока фокус в поле ввода, срабатывает только Escape.
 */
export function useHotkeys(map: Record<string, Handler>, enabled = true) {
  const ref = useRef(map)

  useEffect(() => {
    ref.current = map
  })

  useEffect(() => {
    if (!enabled) return
    function onKey(e: KeyboardEvent) {
      const target = e.target as HTMLElement | null
      const typing =
        target?.tagName === 'INPUT' ||
        target?.tagName === 'TEXTAREA' ||
        target?.tagName === 'SELECT' ||
        Boolean(target?.isContentEditable)

      if (e.key !== 'Escape' && (typing || e.ctrlKey || e.metaKey || e.altKey)) return

      const handler = ref.current[e.key] ?? ref.current[e.key.toLowerCase()]
      if (!handler) return
      if (e.key !== 'Escape') e.preventDefault()
      handler(e)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [enabled])
}
