import { CircleNotch } from '@phosphor-icons/react'
import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../../lib/cn'

type Variant = 'primary' | 'secondary' | 'purple' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
}

/*
  Кнопки в Nocturne контурные: первичное действие — акцентная рамка на
  прозрачном, а не заливка. Заливкой акцент по системе не разливают.
  Вариант purple оставлен как псевдоним второго акцента — палитра моно,
  поэтому визуально он совпадает с первичным.
*/
const variants: Record<Variant, string> = {
  primary: 'btn-primary',
  secondary: 'btn-secondary',
  purple: 'btn-primary',
  ghost: 'btn-ghost',
  danger: 'btn-danger',
}

const sizes: Record<Size, string> = {
  sm: 'btn-sm',
  md: '',
  lg: 'btn-lg',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  className,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      disabled={disabled || loading}
      className={cn('btn', variants[variant], sizes[size], className)}
      {...props}
    >
      {loading && <CircleNotch size={14} className="animate-spin" />}
      {children}
    </button>
  )
}
