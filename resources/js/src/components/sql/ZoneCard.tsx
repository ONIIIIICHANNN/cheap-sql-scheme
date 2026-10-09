import { Check, Trash, X } from '@phosphor-icons/react'
import type { SqlZone } from '../../api/types'
import { cn } from '../../lib/cn'
import { ZONE_COLORS } from '../../lib/sqlDiagram'
import { ConfirmDeleteButton } from '../ui/ConfirmDeleteButton'
import { t } from '../../lib/i18n'

/**
 * Карточка выбранной зоны поверх холста: имя, цвет и удаление. Появляется
 * по клику на зону и сразу после того, как зону нарисовали.
 */
export function ZoneCard({
  zone,
  onUpdate,
  onRemove,
  onClose,
}: {
  zone: SqlZone
  onUpdate: (patch: Partial<SqlZone>) => void
  onRemove: () => void
  onClose: () => void
}) {
  return (
    <div
      className="absolute top-14 right-3 z-20 flex w-[260px] flex-col gap-3 rounded-[16px] bg-surface p-3.5 shadow-[var(--shadow-pop)] [animation:ax-modal-in_.18s_cubic-bezier(.2,.9,.3,1)_both]"
      onPointerDown={(e) => e.stopPropagation()}
    >
      <div className="flex items-center gap-2">
        <span className="size-3 shrink-0 rounded-full" style={{ background: zone.color }} />
        <p className="m-0 flex-1 text-[14px] font-semibold">{t('zone')}</p>
        <button
          onClick={onClose}
          title={t('close')}
          aria-label={t('close')}
          className="grid size-8 place-items-center rounded-[8px] text-muted hover:bg-tint hover:text-fg"
        >
          <X size={15} />
        </button>
      </div>

      <label className="flex flex-col gap-1.5 text-[13px] text-muted">
        {t('nameLabel')}
        <input
          // Ключ по зоне: при выборе другой зоны поле получает фокус заново.
          key={zone.uid}
          autoFocus
          value={zone.label ?? ''}
          onChange={(e) => onUpdate({ label: e.target.value })}
          onKeyDown={(e) => (e.key === 'Enter' || e.key === 'Escape') && onClose()}
          placeholder={t('zoneNamePlaceholder')}
          maxLength={60}
          className="input text-[14px]"
        />
      </label>

      <div className="flex flex-col gap-1.5">
        <span className="text-[13px] text-muted">{t('color')}</span>
        <div className="flex flex-wrap gap-2">
          {ZONE_COLORS.map((color) => (
            <button
              key={color}
              type="button"
              onClick={() => onUpdate({ color })}
              title={color}
              aria-label={t('colorValue', { color })}
              aria-pressed={zone.color === color}
              className={cn(
                'grid size-7 place-items-center rounded-full ring-1 ring-black/15 ring-inset',
                zone.color === color && 'outline-2 outline-offset-2 outline-fg outline-solid',
              )}
              style={{ background: color }}
            >
              {zone.color === color && <Check size={13} weight="bold" className="text-white" />}
            </button>
          ))}
        </div>
      </div>

      <ConfirmDeleteButton
        onConfirm={onRemove}
        confirmLabel={t('confirmDelete')}
        title={t('deleteZone')}
        className="flex min-h-9 items-center gap-1.5 self-start rounded-[10px] px-2.5 text-[13px] text-muted hover:bg-tint hover:text-warm"
      >
        <Trash size={14} />
        {t('deleteZone')}
      </ConfirmDeleteButton>
    </div>
  )
}
