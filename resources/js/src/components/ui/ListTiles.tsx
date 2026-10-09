import { DotsThree } from '@phosphor-icons/react'
import { useEffect, useRef, useState } from 'react'

/** Первая плитка сетки — «создать новое» (шаблон v3). */
export function NewTile({ title, hint, onClick }: { title: string; hint: string; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className="flex min-h-[250px] flex-col items-center justify-center gap-3 rounded-[20px] border-[1.5px] border-dashed border-n700 p-7 text-fg hover:border-lamp-ink hover:bg-tint"
    >
      <span className="ax-new-plus">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round">
          <path d="M12 5v14M5 12h14" />
        </svg>
      </span>
      <span className="text-[18px] font-medium">{title}</span>
      <span className="-mt-2 text-[14px] text-muted">{hint}</span>
    </button>
  )
}

/** «⋯» на карточке списка: удаление в два шага — «Удалить» → «Точно удалить?». */
export function CardMenu({ label, onRemove }: { label: string; onRemove: () => void }) {
  const [open, setOpen] = useState(false)
  const [sure, setSure] = useState(false)
  const box = useRef<HTMLSpanElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) {
        setOpen(false)
        setSure(false)
      }
    }
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    addEventListener('mousedown', close)
    addEventListener('keydown', esc)
    return () => {
      removeEventListener('mousedown', close)
      removeEventListener('keydown', esc)
    }
  }, [open])

  return (
    <span ref={box} className="relative -mt-2.5 -mr-2.5 shrink-0">
      <button
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setOpen((o) => !o)
          setSure(false)
        }}
        title="Ещё"
        aria-label="Ещё"
        aria-expanded={open}
        className="grid size-11 place-items-center rounded-[10px] text-muted hover:bg-tint hover:text-fg"
      >
        <DotsThree size={20} weight="bold" />
      </button>
      {open && (
        <span className="absolute top-[46px] right-0 z-20 grid min-w-[190px] rounded-[14px] bg-surface p-1.5 shadow-[var(--shadow-pop)]">
          <button
            onClick={(e) => {
              e.preventDefault()
              e.stopPropagation()
              if (!sure) return setSure(true)
              setOpen(false)
              onRemove()
            }}
            className="flex min-h-11 items-center rounded-[10px] border px-3 text-left text-[15px] text-danger hover:bg-tint"
            style={{ borderColor: sure ? 'var(--danger)' : 'transparent' }}
          >
            {sure ? 'Точно удалить?' : label}
          </button>
        </span>
      )}
    </span>
  )
}
