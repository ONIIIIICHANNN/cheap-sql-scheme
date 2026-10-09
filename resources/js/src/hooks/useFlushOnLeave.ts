import { useEffect, useRef } from 'react'

/**
 * Флаш несохранённых правок при уходе со страницы.
 *
 * Дебаунс-автосейв теряет последнее окно (до 700 мс), если пользователь уходит
 * раньше. Этот хук отправляет незакоммиченное немедленно в трёх случаях:
 * unmount компонента (SPA-навигация), `beforeunload` (закрытие/перезагрузка
 * вкладки) и `visibilitychange` → `hidden` (сворачивание/переключение — на
 * мобильных единственное надёжное событие перед выгрузкой).
 *
 * `flush` должен сам решать, есть ли что слать, и использовать keepalive-запрос —
 * обычный fetch при выгрузке отменяется.
 */
export function useFlushOnLeave(flush: () => void): void {
  // Держим свежий колбэк в ref, чтобы слушатели вешались один раз и не
  // переподписывались на каждый ре-рендер.
  const flushRef = useRef(flush)
  useEffect(() => {
    flushRef.current = flush
  }, [flush])

  useEffect(() => {
    const onBeforeUnload = () => flushRef.current()
    const onVisibility = () => {
      if (document.visibilityState === 'hidden') flushRef.current()
    }

    window.addEventListener('beforeunload', onBeforeUnload)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload)
      document.removeEventListener('visibilitychange', onVisibility)
      flushRef.current()
    }
  }, [])
}
