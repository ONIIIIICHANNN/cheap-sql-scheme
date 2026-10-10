import { ApiError, api, apiUrl, authHeaders } from './client'
import type {
  SchemaChanges,
  SchemaDrift,
  SqlDialect,
  SqlRelationType,
  SqlReferentialAction,
  SqlSchema,
} from './types'
import { t } from '../lib/i18n'

export interface SqlSchemaInput {
  name: string
  description?: string | null
  dialect?: SqlDialect
}

export interface SqlSchemaUpdateInput {
  name: string
  description?: string | null
  dialect?: SqlDialect
  canvas?: SqlSchema['canvas']
}

/** Полезная нагрузка массового сохранения диаграммы (связи по uid колонок). */
export interface DiagramColumnPayload {
  uid: string
  name: string
  type: string
  length?: string | null
  nullable?: boolean
  is_primary?: boolean
  is_unique?: boolean
  is_index?: boolean
  is_auto_increment?: boolean
  default_value?: string | null
  note?: string | null
}

export interface DiagramTablePayload {
  uid: string
  name: string
  pos_x: number
  pos_y: number
  color?: string | null
  note?: string | null
  columns: DiagramColumnPayload[]
}

export interface DiagramRelationPayload {
  from: string
  to: string
  type?: SqlRelationType
  on_delete?: SqlReferentialAction | null
  on_update?: SqlReferentialAction | null
}

export interface DiagramPayload {
  canvas?: SqlSchema['canvas']
  tables: DiagramTablePayload[]
  relations: DiagramRelationPayload[]
}

export type SqlDumpDialect = 'mysql' | 'pgsql' | 'sqlite'

export interface ExportResult {
  filename: string
  content: string
  /** Та же схема по файлу на таблицу, в порядке зависимостей. */
  files: { filename: string; content: string }[]
}

export const sqlApi = {
  list: () => api.get<SqlSchema[]>('/schemas'),

  create: (input: SqlSchemaInput) => api.post<SqlSchema>('/schemas', input),

  get: (id: number) => api.get<SqlSchema>(`/schemas/${id}`),

  update: (id: number, input: SqlSchemaUpdateInput, opts?: { keepalive?: boolean }) =>
    api.put<SqlSchema>(`/schemas/${id}`, input, opts),

  saveDiagram: (id: number, payload: DiagramPayload, opts?: { keepalive?: boolean }) =>
    api.put<SqlSchema>(`/schemas/${id}/diagram`, payload, opts),

  export: (id: number) => api.get<ExportResult>(`/schemas/${id}/export`),

  /** Структура как .sql-файл; без dialect сервер берёт диалект схемы (или MySQL). */
  exportSql: (id: number, dialect?: SqlDumpDialect) =>
    api.get<{ dialect: SqlDumpDialect; filename: string; content: string }>(
      `/schemas/${id}/export-sql${dialect ? `?dialect=${dialect}` : ''}`,
    ),

  /** Миграции отдельными файлами одним архивом. */
  exportZip: async (id: number): Promise<Blob> => {
    const res = await fetch(apiUrl(`/schemas/${id}/export.zip`), {
      credentials: 'same-origin',
      headers: { Accept: 'application/zip', ...authHeaders() },
    })
    if (!res.ok) throw new ApiError(t('buildZipFailed'), res.status)
    return res.blob()
  },

  import: (input: { name: string; content: string }) =>
    api.post<SqlSchema>('/schemas/import', input),

  /** Что изменилось с последнего коммита, и миграция с этими изменениями. */
  changes: (id: number) => api.get<SchemaChanges>(`/schemas/${id}/changes`),

  /** Зафиксировать изменения; write — записать миграцию в проект. */
  commit: (id: number, input: { write: boolean }) =>
    api.post<{
      files: { filename: string; content: string }[]
      written: boolean
      path: string | null
      /** Несколько файлов при скачивании — архивом (base64). */
      zip: string | null
    }>(
      `/schemas/${id}/commit`,
      input,
    ),

  /** Изменилась ли база проекта вне редактора. */
  drift: (id: number) => api.get<SchemaDrift>(`/schemas/${id}/drift`),

  /** Подтянуть структуру из базы проекта. */
  sync: (id: number) => api.post<SqlSchema>(`/schemas/${id}/sync`),

  remove: (id: number) => api.delete<null>(`/schemas/${id}`),
}
