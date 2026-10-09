import type {
  SqlColumn,
  SqlLineMode,
  SqlReferentialAction,
  SqlRelationType,
  SqlSchema,
  SqlTable,
  SqlZone,
} from '../api/types'
import type { DiagramPayload } from '../api/sql'

/** Связь в локальной модели холста — по uid колонок, а не по id. */
export interface DiagramRelation {
  uid: string
  from: string
  to: string
  type: SqlRelationType
  on_delete: SqlReferentialAction | null
  on_update: SqlReferentialAction | null
}

export interface Diagram {
  tables: SqlTable[]
  relations: DiagramRelation[]
  canvas: { zoom: number; offsetX: number; offsetY: number }
  /** Режим отрисовки связей: гладкие кривые или строгие прямые углы. */
  lineMode: SqlLineMode
  /** Цветные зоны-подложки (видны только в режиме зон). */
  zones: SqlZone[]
}

/** Палитра для зон — низкохромные тона под тёмную землю. */
export const ZONE_COLORS = [
  '#9184d9',
  '#7fbfa3',
  '#d5a878',
  '#e0798a',
  '#7aa7d9',
  '#a7a1db',
] as const

/** Типы колонок для выпадающего списка (значение → метод Blueprint). */
export const COLUMN_TYPES = [
  'id',
  'bigInteger',
  'integer',
  'string',
  'text',
  'boolean',
  'date',
  'dateTime',
  'timestamp',
  'decimal',
  'float',
  'json',
  'uuid',
  'foreignId',
] as const

export const DEFAULT_CANVAS = { zoom: 1, offsetX: 0, offsetY: 0 }

export function newUid(): string {
  return crypto.randomUUID()
}

/** Грубая единственная форма имени таблицы: users → user, categories → category. */
export function singularize(name: string): string {
  if (/ies$/i.test(name)) return name.replace(/ies$/i, 'y')
  if (/(ses|xes|zes|ches|shes)$/i.test(name)) return name.replace(/es$/i, '')
  if (/s$/i.test(name) && !/ss$/i.test(name)) return name.replace(/s$/i, '')
  return name
}

/**
 * Имя колонки внешнего ключа по конвенции Laravel: users.id → user_id,
 * а для не-id ссылок — user_slug и т. п.
 */
export function foreignKeyName(targetTable: string, targetColumn: string): string {
  const base = singularize(targetTable)
  return targetColumn === 'id' ? `${base}_id` : `${base}_${targetColumn}`
}

/** Уникальное имя таблицы: при совпадении добавляет числовой суффикс. */
export function uniqueTableName(desired: string, tables: SqlTable[], selfUid?: string): string {
  const taken = new Set(
    tables.filter((t) => t.uid !== selfUid).map((t) => t.name.trim().toLowerCase()),
  )
  const trimmed = desired.trim() || 'table'
  if (!taken.has(trimmed.toLowerCase())) return trimmed

  let index = 2
  while (taken.has(`${trimmed}_${index}`.toLowerCase())) index++
  return `${trimmed}_${index}`
}

/** Пустая колонка с разумными значениями по умолчанию. */
export function makeColumn(overrides: Partial<SqlColumn> = {}): SqlColumn {
  return {
    uid: newUid(),
    position: 0,
    name: 'column',
    type: 'string',
    length: null,
    nullable: false,
    is_primary: false,
    is_unique: false,
    is_index: false,
    is_auto_increment: false,
    default_value: null,
    note: null,
    ...overrides,
  }
}

/** Предлагаемое имя по умолчанию для новой таблицы (не занятое). */
export function defaultTableName(tables: SqlTable[]): string {
  return uniqueTableName(`table_${tables.length + 1}`, tables)
}

/** Новая зона с разумными размерами по умолчанию. */
export function makeZone(overrides: Partial<SqlZone> = {}): SqlZone {
  return {
    uid: newUid(),
    x: 0,
    y: 0,
    width: 360,
    height: 260,
    color: ZONE_COLORS[0],
    label: 'Зона',
    ...overrides,
  }
}

/** Новая таблица с колонкой первичного ключа `id`. */
export function makeTable(posX: number, posY: number, name = 'new_table'): SqlTable {
  return {
    uid: newUid(),
    name,
    pos_x: Math.round(posX),
    pos_y: Math.round(posY),
    color: null,
    note: null,
    columns: [
      makeColumn({
        name: 'id',
        type: 'id',
        is_primary: true,
        is_auto_increment: true,
        position: 0,
      }),
      // Как в Laravel: при экспорте эти две колонки становятся $table->timestamps().
      makeColumn({ name: 'created_at', type: 'timestamp', nullable: true, position: 1 }),
      makeColumn({ name: 'updated_at', type: 'timestamp', nullable: true, position: 2 }),
    ],
  }
}

/**
 * Ответ сервера → локальная модель. Связи (по id колонок) переводятся
 * в uid-пары, чтобы линии цеплялись к колонкам без обращения к серверу.
 */
export function schemaToDiagram(schema: SqlSchema): Diagram {
  const tables = (schema.tables ?? []).map((table) => ({
    ...table,
    columns: [...(table.columns ?? [])].sort((a, b) => a.position - b.position),
  }))

  const uidByColumnId = new Map<number, string>()
  for (const table of tables) {
    for (const column of table.columns) {
      if (column.id != null) uidByColumnId.set(column.id, column.uid)
    }
  }

  const relations: DiagramRelation[] = []
  for (const relation of schema.relations ?? []) {
    const from = uidByColumnId.get(relation.from_column_id)
    const to = uidByColumnId.get(relation.to_column_id)
    if (from && to) {
      relations.push({
        uid: newUid(),
        from,
        to,
        type: relation.type,
        on_delete: relation.on_delete,
        on_update: relation.on_update,
      })
    }
  }

  const canvas = schema.canvas ?? { ...DEFAULT_CANVAS }
  return {
    tables,
    relations,
    canvas: { zoom: canvas.zoom, offsetX: canvas.offsetX, offsetY: canvas.offsetY },
    lineMode: canvas.lineMode ?? 'smooth',
    zones: canvas.zones ?? [],
  }
}

/** Локальная модель → полезная нагрузка для PUT /diagram. */
export function diagramToPayload(diagram: Diagram): DiagramPayload {
  return {
    canvas: {
      ...diagram.canvas,
      lineMode: diagram.lineMode,
      zones: diagram.zones,
    },
    tables: diagram.tables.map((table) => ({
      uid: table.uid,
      name: table.name,
      pos_x: Math.round(table.pos_x),
      pos_y: Math.round(table.pos_y),
      color: table.color,
      note: table.note,
      columns: table.columns.map((column) => ({
        uid: column.uid,
        name: column.name,
        type: column.type,
        length: column.length,
        nullable: column.nullable,
        is_primary: column.is_primary,
        is_unique: column.is_unique,
        is_index: column.is_index,
        is_auto_increment: column.is_auto_increment,
        default_value: column.default_value,
        note: column.note,
      })),
    })),
    relations: diagram.relations.map((relation) => ({
      from: relation.from,
      to: relation.to,
      type: relation.type,
      on_delete: relation.on_delete,
      on_update: relation.on_update,
    })),
  }
}
