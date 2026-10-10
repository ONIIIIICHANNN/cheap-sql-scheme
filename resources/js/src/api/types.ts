export interface ApiEnvelope<T> {
  data: T
  message: string
  success: boolean
}


// ── SQL-планнер ──────────────────────────────────────────────────────────
// Схема БД на холсте: таблицы с колонками и связи колонка→колонка.

export type SqlDialect = 'laravel' | 'mysql' | 'pgsql' | 'sqlite'
export type SqlRelationType = 'one_to_one' | 'one_to_many' | 'many_to_many'
export type SqlReferentialAction = 'cascade' | 'set null' | 'restrict' | 'no action'

export interface SqlColumn {
  id?: number
  uid: string
  sql_table_id?: number
  position: number
  name: string
  type: string
  length: string | null
  nullable: boolean
  is_primary: boolean
  is_unique: boolean
  is_index: boolean
  is_auto_increment: boolean
  default_value: string | null
  note: string | null
}

export interface SqlTable {
  id?: number
  uid: string
  sql_schema_id?: number
  name: string
  pos_x: number
  pos_y: number
  color: string | null
  note: string | null
  columns: SqlColumn[]
}

/** Связь, как её отдаёт сервер (по id колонок). */
export interface SqlRelation {
  id?: number
  sql_schema_id?: number
  from_column_id: number
  to_column_id: number
  type: SqlRelationType
  on_delete: SqlReferentialAction | null
  on_update: SqlReferentialAction | null
}

export type SqlLineMode = 'smooth' | 'straight'

/** Цветная зона-подложка для группировки связанных таблиц на холсте. */
export interface SqlZone {
  uid: string
  x: number
  y: number
  width: number
  height: number
  color: string
  label: string | null
}

export interface SqlSchemaCanvas {
  zoom: number
  offsetX: number
  offsetY: number
  lineMode?: SqlLineMode
  zones?: SqlZone[]
}

/** Колонка в снимке — то, с чем сравниваются изменения. */
export type SqlBaselineColumn = Pick<
  SqlColumn,
  | 'uid'
  | 'name'
  | 'type'
  | 'length'
  | 'nullable'
  | 'is_primary'
  | 'is_unique'
  | 'is_index'
  | 'is_auto_increment'
  | 'default_value'
>

export interface SqlBaseline {
  tables: { uid: string; name: string; columns: SqlBaselineColumn[] }[]
  relations: {
    from: string
    to: string
    on_delete: SqlReferentialAction | null
    on_update: SqlReferentialAction | null
  }[]
}

export type ChangeStatus = 'added' | 'removed' | 'changed' | 'renamed'

/** Изменения для окна коммита — по таблицам. */
export interface SchemaChangeTable {
  uid: string
  name: string
  old_name: string | null
  status: ChangeStatus
  items: {
    kind: 'column' | 'foreign'
    status: ChangeStatus
    name: string
    old_name: string | null
    before: string | null
    after: string | null
  }[]
}

export interface SchemaChanges {
  changes: SchemaChangeTable[]
  summary: { added: number; removed: number; changed: number }
  empty: boolean
  /** Миграции по файлу на таблицу, в порядке выполнения. */
  migrations: { name: string; content: string }[]
  /** Миграции проекта, которые ещё не выполнены. */
  pending: string[]
  /** «posts.slug» — новые NOT NULL колонки без значения по умолчанию. */
  required_without_default: string[]
  can_write: boolean
  committed_at: string | null
}

/** Изменилась ли база проекта вне редактора. */
export interface SchemaDrift {
  /** false — не проверяли (есть невыполненные миграции или база недоступна). */
  checked: boolean
  changed: boolean
  summary?: SchemaChanges['summary']
  changes?: SchemaChangeTable[]
  pending: string[]
}

export interface SqlSchema {
  id: number
  user_id: number | null
  name: string
  description: string | null
  dialect: SqlDialect
  canvas: SqlSchemaCanvas | null
  /** database — схема базы самого проекта. */
  source: 'database' | null
  /** Снимок структуры на момент последнего коммита или синхронизации. */
  baseline?: SqlBaseline | null
  committed_at?: string | null
  tables_count?: number
  tables?: SqlTable[]
  relations?: SqlRelation[]
  created_at: string
  updated_at: string
}
