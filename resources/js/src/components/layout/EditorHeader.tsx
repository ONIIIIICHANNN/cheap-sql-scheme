import type { CSSProperties, ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '../../lib/cn'
import { ThemeToggle } from '../ui/ThemeToggle'
import { t } from '../../lib/i18n'

export type SaveState = 'idle' | 'saving' | 'saved' | 'error'

const SAVE = (): Record<SaveState, [string, string]> => ({
  idle: ['', ''],
  saving: [t('saving'), 'var(--muted)'],
  saved: [t('saved'), 'var(--muted)'],
  error: [t('notSaved'), 'var(--danger)'],
})

interface Props {
  backTo: string
  backLabel: string
  title: ReactNode
  meta: ReactNode
  save?: SaveState
  /** Что-то рядом с названием — например, наклейка «доска закрыта!». */
  titleExtra?: ReactNode
  children?: ReactNode
  className?: string
  style?: CSSProperties
  /** Низкая шапка (48px) — больше места под холст. */
  compact?: boolean
}

/**
 * Шапка рабочих экранов v3 (доска, SQL-схема, доска рисования):
 * «← назад» · название Unbounded · мета-строка со статусом сохранения ·
 * контурные действия 44px справа и переключатель темы последним.
 */
export function EditorHeader({
  backTo,
  backLabel,
  title,
  meta,
  save = 'idle',
  titleExtra,
  children,
  className,
  style,
  compact = false,
}: Props) {
  const [saveText, saveColor] = SAVE()[save]
  return (
    <header
      className={cn(
        'relative z-[2] flex shrink-0 items-center gap-1.5 bg-surface px-2.5 shadow-[inset_0_-1px_0_var(--line)] min-[900px]:gap-3 min-[900px]:px-5',
        compact ? 'min-h-12 py-1' : 'min-h-16 py-2.5',
        className,
      )}
      style={style}
    >
      <Link
        to={backTo}
        aria-label={t('backTo', { label: backLabel })}
        className="flex min-h-11 min-w-11 flex-none items-center justify-center gap-2 rounded-[10px] text-[15px] text-muted hover:bg-tint hover:text-fg min-[900px]:px-2.5"
      >
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        <span className="hidden min-[900px]:inline">{backLabel}</span>
      </Link>
      <span className="hidden h-7 w-px flex-none bg-line min-[900px]:block" />
      <div className="relative min-w-0 flex-1">
        <p
          className={cn(
            'm-0 truncate font-num leading-[1.25] font-semibold',
            compact ? 'text-[15px] min-[900px]:text-[16px]' : 'text-[16px] min-[900px]:text-[20px]',
          )}
        >
          {title}
        </p>
        <p
          className={cn(
            'mb-0 flex items-center gap-2 overflow-hidden whitespace-nowrap text-muted',
            compact ? 'mt-0 text-[12px]' : 'mt-[3px] text-[13px]',
          )}
        >
          <span className="min-w-0 truncate">{meta}</span>
          {saveText && (
            <span
              className="inline-flex flex-none items-center gap-1.5"
              style={{ color: saveColor }}
            >
              <span className="size-1.5 rounded-full" style={{ background: saveColor }} />
              {saveText}
            </span>
          )}
        </p>
        {titleExtra}
      </div>
      <div className="flex flex-none items-center gap-1.5">
        {children}
        <ThemeToggle />
      </div>
    </header>
  )
}

interface ActionProps {
  icon: ReactNode
  label: string
  onClick: () => void
  pressed?: boolean
  /** На телефоне подпись прячется; iconOnly — прячется всегда. */
  iconOnly?: boolean
}

/** Контурная кнопка 44px для шапки редактора. */
export function EditorAction({ icon, label, onClick, pressed, iconOnly }: ActionProps) {
  return (
    <button
      onClick={onClick}
      title={label}
      aria-label={label}
      aria-pressed={pressed}
      className="flex min-h-11 min-w-11 flex-none items-center justify-center gap-2 rounded-[10px] border border-line text-[14px] font-medium whitespace-nowrap text-fg hover:bg-tint min-[900px]:px-3.5"
      style={{ background: pressed ? 'var(--tint)' : 'transparent' }}
    >
      <span className="grid place-items-center">{icon}</span>
      {!iconOnly && <span className="hidden min-[900px]:inline">{label}</span>}
    </button>
  )
}

const ico = (d: ReactNode) => (
  <svg
    width="18"
    height="18"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.7"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    {d}
  </svg>
)

// eslint-disable-next-line react-refresh/only-export-components
export const EditorIcon = {
  gear: ico(
    <>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
    </>,
  ),
  export: ico(
    <>
      <path d="M12 3v12M7 8l5-5 5 5" />
      <path d="M5 15v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" />
    </>,
  ),
  eye: ico(
    <>
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
      <circle cx="12" cy="12" r="3" />
    </>,
  ),
  eyeOff: ico(
    <>
      <path d="M3 3l18 18" />
      <path d="M10.6 5.6A9.7 9.7 0 0 1 12 5.5C18 5.5 21.5 12 21.5 12a17 17 0 0 1-3.2 3.9M6.2 7.2A16.6 16.6 0 0 0 2.5 12S6 18.5 12 18.5a9 9 0 0 0 4.2-1" />
    </>,
  ),
  pen: ico(<path d="m4 20 4-1 11-11-3-3L5 16l-1 4Z" />),
  archive: ico(
    <>
      <rect x="3" y="4" width="18" height="5" rx="1.5" />
      <path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9M10 13h4" />
    </>,
  ),
  wood: ico(
    <>
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <path d="M3 9h18M3 15h18M8 4v5M15 9v6M10 15v5" />
    </>,
  ),
}
