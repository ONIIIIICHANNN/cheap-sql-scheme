import type { SqlBaseline, SqlColumn } from '../api/types'
import type { Diagram } from './sqlDiagram'

/**
 * Быстрая разница «холст ↔ последний коммит» для пометок на холсте и
 * счётчика в шапке. Сопоставление по uid — как на сервере; точный список
 * изменений и саму миграцию собирает сервер.
 */
export type ColumnMark = 'added' | 'changed'

export interface ChangeMarks {
  /** Сколько изменений всего — для «Changes · N». */
  count: number
  /**
   * Пометки таблицы одной строкой: «added» или «changed|uid:added,uid:changed».
   * Строка, а не объект, — чтобы memo у узла таблицы не сбрасывался на каждом кадре.
   */
  tables: Map<string, string>
}

const FIELDS = [
  'type',
  'length',
  'nullable',
  'is_primary',
  'is_unique',
  'is_index',
  'is_auto_increment',
  'default_value',
] as const

function norm(field: (typeof FIELDS)[number], value: unknown): string {
  if (field === 'type') return String(value ?? '').toLowerCase()
  if (field === 'length') return String(value ?? '').replace(/\s*,\s*/g, ', ').trim()
  if (field === 'default_value') return value === null || value === undefined ? '' : String(value)
  return value ? '1' : ''
}

function columnChanged(a: Partial<SqlColumn>, b: Partial<SqlColumn>): boolean {
  return a.name !== b.name || FIELDS.some((f) => norm(f, a[f]) !== norm(f, b[f]))
}

const action = (a: string | null | undefined) => (!a || a === 'no action' ? '' : a)

export function changeMarks(diagram: Diagram, baseline: SqlBaseline | null | undefined): ChangeMarks {
  const tables = new Map<string, string>()

  // Без снимка всё новое: подсвечивать весь холст зелёным незачем.
  if (!baseline) {
    const count = diagram.tables.length
    return { count, tables }
  }

  let count = 0
  const baseTables = new Map(baseline.tables.map((t) => [t.uid, t]))
  const currentUids = new Set(diagram.tables.map((t) => t.uid))
  const columnMarks = new Map<string, Map<string, ColumnMark>>()
  const columnTable = new Map<string, string>()

  for (const table of diagram.tables) {
    for (const c of table.columns) columnTable.set(c.uid, table.uid)
    const base = baseTables.get(table.uid)
    if (!base) {
      tables.set(table.uid, 'added')
      count++
      continue
    }
    const marks = new Map<string, ColumnMark>()
    const baseCols = new Map(base.columns.map((c) => [c.uid, c]))
    for (const column of table.columns) {
      const old = baseCols.get(column.uid)
      if (!old) marks.set(column.uid, 'added')
      else if (columnChanged(old, column)) marks.set(column.uid, 'changed')
    }
    const removed = base.columns.filter((c) => !table.columns.some((x) => x.uid === c.uid)).length
    count += marks.size + removed + (base.name !== table.name ? 1 : 0)
    if (marks.size || removed || base.name !== table.name) columnMarks.set(table.uid, marks)
  }
  count += baseline.tables.filter((t) => !currentUids.has(t.uid)).length

  // Внешние ключи: новый, снятый или с другой целью — пометка на колонке-ссылке.
  // Ключ новой (удаляемой) колонки или таблицы — часть её, отдельно не считаем.
  const baseColumns = new Set(baseline.tables.flatMap((t) => t.columns.map((c) => c.uid)))
  const baseRel = new Map(baseline.relations.map((r) => [r.from, r]))
  const curRel = new Map(diagram.relations.map((r) => [r.from, r]))
  const relChanged = (from: string) => {
    const tableUid = columnTable.get(from)
    if (!tableUid || tables.get(tableUid) === 'added') return
    const marks = columnMarks.get(tableUid) ?? new Map<string, ColumnMark>()
    if (!marks.has(from)) marks.set(from, 'changed')
    columnMarks.set(tableUid, marks)
  }
  for (const [from, r] of curRel) {
    const b = baseRel.get(from)
    if (
      !b ||
      b.to !== r.to ||
      action(b.on_delete) !== action(r.on_delete) ||
      action(b.on_update) !== action(r.on_update)
    ) {
      if (baseColumns.has(from)) count++
      relChanged(from)
    }
  }
  for (const from of baseRel.keys()) {
    if (!curRel.has(from)) {
      if (columnTable.has(from)) count++
      relChanged(from)
    }
  }

  for (const [uid, marks] of columnMarks) {
    const parts = [...marks].map(([c, m]) => `${c}:${m}`).join(',')
    tables.set(uid, `changed|${parts}`)
  }

  return { count, tables }
}

/** Разобрать строку пометок таблицы обратно. */
export function parseMark(mark: string | undefined): {
  table: 'added' | 'changed' | null
  columns: Map<string, ColumnMark>
} {
  if (!mark) return { table: null, columns: new Map() }
  if (mark === 'added') return { table: 'added', columns: new Map() }
  const [, list = ''] = mark.split('|')
  const columns = new Map<string, ColumnMark>()
  for (const part of list.split(',').filter(Boolean)) {
    const [uid, m] = part.split(':')
    columns.set(uid, m as ColumnMark)
  }
  return { table: 'changed', columns }
}
