import {
  DndContext,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
  type Modifier,
} from '@dnd-kit/core'
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { ArrowLeft, DotsSixVertical, Lock, Plus, Trash } from '@phosphor-icons/react'
import { useState, type PointerEvent as ReactPointerEvent } from 'react'
import type { SqlColumn, SqlTable } from '../../api/types'
import type { DiagramRelation } from '../../lib/sqlDiagram'
import { COLUMN_TYPES } from '../../lib/sqlDiagram'
import { cn } from '../../lib/cn'
import { ForeignKeyModal, type ForeignKeyTarget } from './ForeignKeyModal'

/** Перетаскивание колонок строго по вертикали — без смещения по горизонтали. */
const restrictToVerticalAxis: Modifier = ({ transform }) => ({ ...transform, x: 0 })

interface TableEditorPanelProps {
  table: SqlTable
  allTables: SqlTable[]
  relations: DiagramRelation[]
  onBack: () => void
  onUpdateTable: (patch: Partial<SqlTable>) => void
  onRemoveTable: () => void
  onAddColumn: () => void
  onUpdateColumn: (columnUid: string, patch: Partial<SqlColumn>) => void
  onRemoveColumn: (columnUid: string) => void
  onReorderColumns: (fromIndex: number, toIndex: number) => void
  onAddRelation: (from: string, to: string) => void
  onRemoveRelation: (relationUid: string) => void
  onRequestNewTable: () => void
}

/** Флаг-переключатель в одну строку со стилизованным чекбоксом. */
function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string
  checked: boolean
  onChange: (value: boolean) => void
}) {
  return (
    <label className="flex cursor-pointer items-center gap-1.5 text-[11.5px] text-neutral-400 select-none">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="checkbox"
      />
      {label}
    </label>
  )
}

interface ColumnCardProps {
  column: SqlColumn
  fk: DiagramRelation | undefined
  fkLabel: string | null
  deletable: boolean
  onUpdate: (patch: Partial<SqlColumn>) => void
  onRemove: () => void
  onToggleFk: (next: boolean) => void
  onEditFk: () => void
}

function ColumnCard({
  column,
  fk,
  fkLabel,
  deletable,
  onUpdate,
  onRemove,
  onToggleFk,
  onEditFk,
}: ColumnCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: column.uid,
  })

  // Интерактивные элементы не должны запускать перетаскивание карточки.
  const stopDrag = (e: ReactPointerEvent) => e.stopPropagation()

  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'cursor-grab touch-none rounded-lg border border-divider bg-surface p-2.5 shadow-[var(--shadow-sm)] transition-shadow select-none active:cursor-grabbing',
        isDragging
          ? 'z-10 opacity-90 shadow-[var(--shadow-md)]'
          : 'hover:shadow-[var(--shadow-md)]',
      )}
    >
      <div className="flex items-center gap-1.5">
        <span className="grid size-6 shrink-0 place-items-center text-neutral-600">
          <DotsSixVertical size={14} />
        </span>
        <input
          value={column.name}
          onChange={(e) => onUpdate({ name: e.target.value })}
          onPointerDown={stopDrag}
          className="input h-8 min-w-0 flex-1 text-[12.5px] select-text"
          aria-label="Имя колонки"
        />
        <select
          value={column.type}
          onChange={(e) => onUpdate({ type: e.target.value })}
          onPointerDown={stopDrag}
          className="input h-8 w-[92px] text-[12px]"
          aria-label="Тип колонки"
        >
          {COLUMN_TYPES.map((type) => (
            <option key={type} value={type} className="bg-surface text-fg">
              {type}
            </option>
          ))}
        </select>
        {deletable ? (
          <button
            onClick={onRemove}
            onPointerDown={stopDrag}
            title="Удалить колонку"
            className="grid size-8 shrink-0 place-items-center rounded-sm text-neutral-600 transition-colors hover:bg-danger/10 hover:text-danger"
          >
            <Trash size={13} />
          </button>
        ) : (
          <span
            title="Первичный ключ нельзя удалить"
            className="grid size-8 shrink-0 place-items-center rounded-sm text-neutral-700"
          >
            <Lock size={12} />
          </span>
        )}
      </div>

      <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1.5" onPointerDown={stopDrag}>
        <Toggle
          label="PK"
          checked={column.is_primary}
          onChange={(v) => onUpdate({ is_primary: v })}
        />
        <Toggle
          label="null"
          checked={column.nullable}
          onChange={(v) => onUpdate({ nullable: v })}
        />
        <Toggle
          label="unique"
          checked={column.is_unique}
          onChange={(v) => onUpdate({ is_unique: v })}
        />
        <Toggle
          label="index"
          checked={column.is_index}
          onChange={(v) => onUpdate({ is_index: v })}
        />
        <Toggle
          label="A_I"
          checked={column.is_auto_increment}
          onChange={(v) => onUpdate({ is_auto_increment: v })}
        />
      </div>

      {/* Внешний ключ: галочка открывает модалку выбора цели. */}
      <div className="mt-2 border-t border-divider pt-2" onPointerDown={stopDrag}>
        <Toggle label="Внешний ключ (FK)" checked={!!fk} onChange={onToggleFk} />
        {fk && (
          <button
            type="button"
            onClick={onEditFk}
            className="mt-1.5 flex h-8 w-full items-center justify-between rounded-sm border border-divider px-2.5 text-[12px] text-neutral-300 transition-colors hover:border-accent-700 hover:text-accent"
          >
            <span className="truncate">→ {fkLabel ?? 'выбрать колонку'}</span>
            <span className="text-[11px] text-neutral-500">изменить</span>
          </button>
        )}
      </div>
    </div>
  )
}

export function TableEditorPanel({
  table,
  allTables,
  relations,
  onBack,
  onUpdateTable,
  onRemoveTable,
  onAddColumn,
  onUpdateColumn,
  onRemoveColumn,
  onReorderColumns,
  onAddRelation,
  onRemoveRelation,
  onRequestNewTable,
}: TableEditorPanelProps) {
  const [fkModalFor, setFkModalFor] = useState<string | null>(null)

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }))

  // Колонки других таблиц — кандидаты для ссылки внешнего ключа.
  const foreignTargets: ForeignKeyTarget[] = allTables
    .filter((t) => t.uid !== table.uid)
    .flatMap((t) =>
      t.columns.map((c) => ({
        value: c.uid,
        tableUid: t.uid,
        tableName: t.name,
        columnName: c.name,
        columnType: c.type,
        isKey: c.is_primary || c.is_unique,
      })),
    )

  function labelForTarget(uid: string | undefined): string | null {
    if (!uid) return null
    const target = foreignTargets.find((t) => t.value === uid)
    return target ? `${target.tableName}.${target.columnName}` : null
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const from = table.columns.findIndex((c) => c.uid === active.id)
    const to = table.columns.findIndex((c) => c.uid === over.id)
    if (from >= 0 && to >= 0) onReorderColumns(from, to)
  }

  const fkColumn = fkModalFor ? table.columns.find((c) => c.uid === fkModalFor) : null
  const fkRelation = fkModalFor ? relations.find((r) => r.from === fkModalFor) : undefined

  return (
    <aside className="flex h-full w-[320px] shrink-0 flex-col border-r border-divider bg-surface select-none">
      <div className="flex items-center gap-2 border-b border-divider px-3 py-3">
        <button
          onClick={onBack}
          title="К списку таблиц"
          className="grid size-8 shrink-0 place-items-center rounded-sm text-neutral-500 transition-colors hover:bg-[rgb(var(--ink)/0.06)] hover:text-fg"
        >
          <ArrowLeft size={15} />
        </button>
        <input
          value={table.name}
          onChange={(e) => onUpdateTable({ name: e.target.value })}
          className="input flex-1 font-semibold select-text"
          aria-label="Имя таблицы"
        />
        <button
          onClick={onRemoveTable}
          title="Удалить таблицу"
          className="grid size-8 shrink-0 place-items-center rounded-sm text-neutral-500 transition-colors hover:bg-danger/10 hover:text-danger"
        >
          <Trash size={15} />
        </button>
      </div>

      <div className="min-h-0 flex-1 space-y-2.5 overflow-y-auto px-4 py-3.5">
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          modifiers={[restrictToVerticalAxis]}
          onDragEnd={handleDragEnd}
        >
          <SortableContext
            items={table.columns.map((c) => c.uid)}
            strategy={verticalListSortingStrategy}
          >
            <div className="space-y-2.5">
              {table.columns.map((column) => {
                const fk = relations.find((r) => r.from === column.uid)
                // Первичный ключ / id защищён от удаления.
                const deletable = !column.is_primary

                return (
                  <ColumnCard
                    key={column.uid}
                    column={column}
                    fk={fk}
                    fkLabel={labelForTarget(fk?.to)}
                    deletable={deletable}
                    onUpdate={(patch) => onUpdateColumn(column.uid, patch)}
                    onRemove={() => onRemoveColumn(column.uid)}
                    onToggleFk={(next) => {
                      if (!next && fk) {
                        onRemoveRelation(fk.uid)
                      } else if (next) {
                        setFkModalFor(column.uid)
                      }
                    }}
                    onEditFk={() => setFkModalFor(column.uid)}
                  />
                )
              })}
            </div>
          </SortableContext>
        </DndContext>

        <button
          onClick={onAddColumn}
          className="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-divider py-2 text-[12.5px] text-neutral-500 transition-colors hover:border-accent-700 hover:text-accent"
        >
          <Plus size={13} /> Колонка
        </button>
      </div>

      <ForeignKeyModal
        open={fkModalFor !== null}
        columnName={fkColumn?.name ?? ''}
        targets={foreignTargets}
        currentTarget={fkRelation?.to}
        onClose={() => setFkModalFor(null)}
        onConfirm={(targetUid) => {
          if (!fkColumn) return
          if (fkRelation) onRemoveRelation(fkRelation.uid)
          onAddRelation(fkColumn.uid, targetUid)
        }}
        onCreateTable={() => {
          setFkModalFor(null)
          onRequestNewTable()
        }}
      />
    </aside>
  )
}
