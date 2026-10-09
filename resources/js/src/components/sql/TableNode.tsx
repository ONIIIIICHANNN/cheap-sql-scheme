import { Key, LinkSimple } from '@phosphor-icons/react'
import { memo } from 'react'
import type { PointerEvent as ReactPointerEvent } from 'react'
import type { SqlColumn, SqlTable } from '../../api/types'
import { parseMark } from '../../lib/changes'
import { cn } from '../../lib/cn'
import { HEADER_HEIGHT, ROW_HEIGHT, TABLE_WIDTH } from './geometry'
import { t } from '../../lib/i18n'

interface TableNodeProps {
  table: SqlTable
  selected: boolean
  linkingFrom: string | null
  onSelect: (uid: string) => void
  onHeaderPointerDown: (e: ReactPointerEvent, uid: string) => void
  onColumnLinkPointerDown: (e: ReactPointerEvent, columnUid: string) => void
  /** Что изменилось с последнего коммита (см. lib/changes). */
  mark?: string
}

/** Короткая метка ключей/ограничений колонки для правого края строки. */
function columnBadge(column: SqlColumn): string {
  if (column.is_primary) return 'PK'
  if (column.is_unique) return 'UQ'
  if (column.is_index) return 'IX'
  return ''
}

/**
 * Узел таблицы. Обёрнут в memo — при перетаскивании одной таблицы
 * перерисовывается только она, а не весь холст (важно на больших схемах).
 */
function TableNodeInner({
  table,
  selected,
  linkingFrom,
  onSelect,
  onHeaderPointerDown,
  onColumnLinkPointerDown,
  mark,
}: TableNodeProps) {
  const changes = parseMark(mark)
  return (
    <div
      className={cn(
        'absolute overflow-hidden rounded-lg bg-surface shadow-[var(--shadow-md)] select-none transition-shadow',
        selected
          ? 'shadow-[0_0_0_2px_var(--color-accent),0_8px_28px_-6px_color-mix(in_srgb,var(--color-accent)_55%,transparent)]'
          : 'shadow-[0_0_0_1px_rgb(var(--ink)/0.1),var(--shadow-md)]',
      )}
      style={{ left: table.pos_x, top: table.pos_y, width: TABLE_WIDTH }}
      onPointerDown={() => onSelect(table.uid)}
    >
      {/* Шапка — она же ручка перетаскивания. У выбранной таблицы горит синим. */}
      <div
        className={cn(
          'flex cursor-grab items-center gap-1.5 border-b px-3 text-[13px] font-semibold tracking-[-0.01em] active:cursor-grabbing',
          selected
            ? 'border-accent-700 bg-accent text-accent-100'
            : 'border-divider bg-[rgb(var(--ink)/0.04)]',
        )}
        style={{ height: HEADER_HEIGHT }}
        onPointerDown={(e) => onHeaderPointerDown(e, table.uid)}
      >
        {changes.table && (
          <span
            title={changes.table === 'added' ? t('markAdded') : t('markChanged')}
            className={cn(
              'size-2 shrink-0 rounded-full',
              changes.table === 'added' ? 'bg-done' : 'bg-lamp',
            )}
          />
        )}
        <span className="truncate">{table.name}</span>
        {table.color && (
          <span className="ml-auto size-2 rounded-full" style={{ background: table.color }} />
        )}
      </div>

      {table.columns.map((column) => (
        <div
          key={column.uid}
          data-column-uid={column.uid}
          className={cn(
            'group relative flex items-center gap-2 px-3 text-[12.5px]',
            linkingFrom && linkingFrom !== column.uid && 'hover:bg-accent/10',
          )}
          style={{ height: ROW_HEIGHT }}
        >
          {changes.columns.has(column.uid) && (
            <span
              aria-hidden
              className={cn(
                'absolute top-1 bottom-1 left-0 w-[3px] rounded-r-full',
                changes.columns.get(column.uid) === 'added' ? 'bg-done' : 'bg-lamp',
              )}
            />
          )}
          <Key
            size={12}
            weight={column.is_primary ? 'fill' : 'regular'}
            className={cn('shrink-0', column.is_primary ? 'text-accent' : 'text-neutral-600')}
          />
          <span className={cn('truncate', column.nullable ? 'text-neutral-400' : 'text-fg')}>
            {column.name}
          </span>
          <span className="ml-auto truncate text-[11px] text-neutral-500">
            {column.type}
            {column.length ? `(${column.length})` : ''}
          </span>
          {columnBadge(column) && (
            <span className="rounded-[4px] bg-neutral-800 px-1 text-[9px] font-semibold text-neutral-300">
              {columnBadge(column)}
            </span>
          )}

          {/* Ручка связи: тянем от колонки к колонке другой таблицы. */}
          <button
            type="button"
            title={t('linkToColumn')}
            className="absolute top-1/2 -right-2 grid size-4 -translate-y-1/2 place-items-center rounded-full bg-accent text-accent-100 opacity-0 shadow-sm transition-opacity group-hover:opacity-100"
            onPointerDown={(e) => {
              e.stopPropagation()
              onColumnLinkPointerDown(e, column.uid)
            }}
          >
            <LinkSimple size={9} />
          </button>
        </div>
      ))}
    </div>
  )
}

export const TableNode = memo(TableNodeInner)
