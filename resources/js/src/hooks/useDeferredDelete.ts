import { useCallback, useMemo, useRef } from 'react'
import { useFlushOnLeave } from './useFlushOnLeave'

/** Сколько живёт «Вернуть» в тосте — столько же ждёт удаление. */
export const UNDO_WINDOW_MS = 6000

/**
 * Отложенное удаление ради честного «Вернуть»: запись сразу прячется,
 * а запрос уходит только через UNDO_WINDOW_MS. Отмена в этом окне —
 * просто снятие таймера, сервер ничего не узнаёт.
 *
 * При уходе со страницы всё отложенное досылается сразу (keepalive),
 * иначе удалённое «воскресло» бы после перезагрузки.
 */
export function useDeferredDelete(commit: (id: number, keepalive: boolean) => Promise<unknown>) {
  const pending = useRef(new Map<number, number>())

  const schedule = useCallback(
    (id: number, onFail?: () => void) => {
      window.clearTimeout(pending.current.get(id))
      const timer = window.setTimeout(() => {
        pending.current.delete(id)
        commit(id, false).catch(() => onFail?.())
      }, UNDO_WINDOW_MS)
      pending.current.set(id, timer)
    },
    [commit],
  )

  const cancel = useCallback((id: number) => {
    window.clearTimeout(pending.current.get(id))
    pending.current.delete(id)
  }, [])

  useFlushOnLeave(() => {
    for (const [id, timer] of pending.current) {
      window.clearTimeout(timer)
      void commit(id, true).catch(() => {})
    }
    pending.current.clear()
  })

  return useMemo(() => ({ schedule, cancel }), [schedule, cancel])
}
