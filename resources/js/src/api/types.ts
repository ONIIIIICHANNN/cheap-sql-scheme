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

export interface SqlSchema {
  id: number
  user_id: number | null
  name: string
  description: string | null
  dialect: SqlDialect
  canvas: SqlSchemaCanvas | null
  tables_count?: number
  tables?: SqlTable[]
  relations?: SqlRelation[]
  created_at: string
  updated_at: string
}
