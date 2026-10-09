import type { InputHTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string
  error?: string
  hint?: ReactNode
  icon?: ReactNode
}

export function Input({ label, error, hint, icon, className, id, ...props }: FieldProps) {
  const inputId = id ?? props.name

  return (
    <div className="field flex flex-col">
      {label && <label htmlFor={inputId}>{label}</label>}
      <div className="relative">
        {icon && (
          <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-neutral-600">
            {icon}
          </span>
        )}
        <input
          id={inputId}
          className={cn(
            'input',
            icon ? 'pl-8' : undefined,
            error && 'border-danger hover:border-danger focus-visible:border-danger',
            className,
          )}
          {...props}
        />
      </div>
      {error ? (
        <span className="mt-1 text-[11.5px] text-danger">{error}</span>
      ) : hint ? (
        <span className="mt-1 text-[11.5px] text-neutral-600">{hint}</span>
      ) : null}
    </div>
  )
}
