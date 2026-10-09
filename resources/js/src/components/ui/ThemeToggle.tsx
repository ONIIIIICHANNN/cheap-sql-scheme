import { cn } from '../../lib/cn'
import { useTheme } from '../../theme/ThemeContext'
import { NavIcon } from '../layout/navIcons'
import { t } from '../../lib/i18n'

interface ThemeToggleProps {
  /** icon — квадратная кнопка в углу; row — строка со подписью в меню. */
  variant?: 'icon' | 'row'
  className?: string
}

export function ThemeToggle({ variant = 'icon', className }: ThemeToggleProps) {
  const { theme, toggle } = useTheme()
  const label = theme === 'dark' ? t('themeLight') : t('themeDark')
  const icon = theme === 'dark' ? NavIcon.sun : NavIcon.moon

  if (variant === 'row') {
    return (
      <button
        onClick={toggle}
        className={cn(
          'flex min-h-11 items-center gap-3 rounded-[10px] px-3 text-left text-[15px] text-muted hover:bg-tint hover:text-fg',
          className,
        )}
      >
        <span className="grid size-5 place-items-center">{icon}</span>
        {label}
      </button>
    )
  }

  return (
    <button
      onClick={toggle}
      title={label}
      aria-label={label}
      className={cn(
        'grid size-11 place-items-center rounded-[10px] text-muted hover:bg-tint hover:text-fg',
        className,
      )}
    >
      {icon}
    </button>
  )
}
