import { DownloadSimple, GearSix, Plus, Table as TableIcon, Trash } from '@phosphor-icons/react'
import type { SqlTable } from '../../api/types'
import { cn } from '../../lib/cn'

interface SchemaSidebarProps {
  schemaName: string
  tables: SqlTable[]
  selectedTable: string | null
  onOpenSettings: () => void
  onSelectTable: (uid: string) => void
  onAddTable: () => void
  onRemoveTable: (uid: string) => void
  onExport: () => void
}

/**
 * Левый сайдбар по умолчанию: имя БД (только для чтения), кнопка настроек,
 * действия и список таблиц. Открыт, пока не выбрана конкретная таблица.
 */
export function SchemaSidebar({
  schemaName,
  tables,
  selectedTable,
  onOpenSettings,
  onSelectTable,
  onAddTable,
  onRemoveTable,
  onExport,
}: SchemaSidebarProps) {
  return (
    <aside className="flex h-full w-[320px] shrink-0 flex-col border-r border-divider bg-surface select-none">
      <div className="border-b border-divider px-4 py-3.5">
        <span className="mb-1 block text-[11px] tracking-wide text-neutral-500 uppercase">
          База данных
        </span>
        <p className="truncate text-[16px] font-semibold tracking-[-0.01em]" title={schemaName}>
          {schemaName}
        </p>
        <div className="mt-2 flex items-center gap-2">
          <span className="text-[11px] text-neutral-500">{tables.length} таблиц</span>
        </div>

        <button
          onClick={onOpenSettings}
          className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-divider py-2 text-[12.5px] text-neutral-300 transition-colors hover:border-accent-700 hover:text-accent"
        >
          <GearSix size={14} /> Настройки
        </button>
      </div>

      <div className="flex items-center justify-between px-4 pt-3 pb-2">
        <span className="text-[11px] tracking-wide text-neutral-500 uppercase">Таблицы</span>
      </div>

      <div className="px-3">
        <button
          onClick={onAddTable}
          className="flex w-full items-center justify-center gap-1.5 rounded-lg border border-accent/40 bg-accent/8 py-2 text-[12.5px] font-medium text-accent transition-colors hover:bg-accent/14"
        >
          <Plus size={14} /> Добавить таблицу
        </button>
      </div>

      <div className="mt-2 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
        {tables.length === 0 ? (
          <p className="px-2 py-4 text-center text-[12px] leading-[1.5] text-neutral-500">
            Пока пусто. Добавьте таблицу — она появится на холсте.
          </p>
        ) : (
          tables.map((table) => (
            <div
              key={table.uid}
              className={cn(
                'group flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-[13px] transition-colors',
                selectedTable === table.uid
                  ? 'bg-accent/12 text-accent'
                  : 'text-neutral-300 hover:bg-[rgb(var(--ink)/0.05)]',
              )}
              onClick={() => onSelectTable(table.uid)}
            >
              <TableIcon size={14} className="shrink-0" />
              <span className="min-w-0 flex-1 truncate">{table.name}</span>
              <span className="text-[10.5px] text-neutral-600">{table.columns.length}</span>
              <button
                onClick={(e) => {
                  e.stopPropagation()
                  if (confirm(`Удалить таблицу «${table.name}»?`)) onRemoveTable(table.uid)
                }}
                title="Удалить таблицу"
                className="grid size-5 shrink-0 place-items-center rounded-sm text-neutral-600 opacity-0 transition-all group-hover:opacity-100 hover:text-danger"
              >
                <Trash size={12} />
              </button>
            </div>
          ))
        )}
      </div>

      <div className="border-t border-divider p-3">
        <button
          onClick={onExport}
          className="flex w-full items-center justify-center gap-2 rounded-lg border border-divider py-2 text-[12.5px] text-neutral-300 transition-colors hover:border-accent-700 hover:text-accent"
        >
          <DownloadSimple size={14} /> Экспорт в миграцию
        </button>
      </div>
    </aside>
  )
}
