import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../api/client'
import { sqlApi } from '../api/sql'
import type { SqlColumn, SqlLineMode, SqlSchema, SqlTable, SqlZone } from '../api/types'
import {
  type Diagram,
  type DiagramRelation,
  diagramToPayload,
  foreignKeyName,
  makeColumn,
  makeTable,
  newUid,
  schemaToDiagram,
  uniqueTableName,
} from '../lib/sqlDiagram'
import { useFlushOnLeave } from './useFlushOnLeave'
import { t } from '../lib/i18n'

/** Новая колонка встаёт перед created_at / updated_at, если они в конце таблицы. */
function withNewColumn(columns: SqlColumn[]): SqlColumn[] {
  let at = columns.length
  while (at > 0 && ['created_at', 'updated_at'].includes(columns[at - 1].name.toLowerCase())) at--
  const next = [...columns.slice(0, at), makeColumn(), ...columns.slice(at)]
  return next.map((c, i) => ({ ...c, position: i }))
}

export type SaveState = 'idle' | 'saving' | 'saved' | 'error'

const AUTOSAVE_DELAY = 700

interface UseSqlSchema {
  schema: SqlSchema | null
  diagram: Diagram | null
  loading: boolean
  error: string | null
  saveState: SaveState
  addTable: (posX: number, posY: number, name?: string) => void
  updateTable: (uid: string, patch: Partial<SqlTable>) => void
  moveTable: (uid: string, posX: number, posY: number) => void
  removeTable: (uid: string) => void
  addColumn: (tableUid: string) => void
  updateColumn: (tableUid: string, columnUid: string, patch: Partial<SqlColumn>) => void
  removeColumn: (tableUid: string, columnUid: string) => void
  reorderColumns: (tableUid: string, fromIndex: number, toIndex: number) => void
  addRelation: (fromColumnUid: string, toColumnUid: string) => void
  removeRelation: (relationUid: string) => void
  setCanvas: (canvas: Diagram['canvas']) => void
  setLineMode: (mode: SqlLineMode) => void
  addZone: (zone: SqlZone) => void
  updateZone: (uid: string, patch: Partial<SqlZone>) => void
  removeZone: (uid: string) => void
  updateSettings: (input: { name?: string }) => Promise<void>
  /** Сохранить несохранённые правки прямо сейчас (перед коммитом). */
  saveNow: () => Promise<void>
  /** Перечитать схему с сервера (после коммита или синхронизации); камера остаётся. */
  reload: () => Promise<void>
}

export function useSqlSchema(id: number): UseSqlSchema {
  const [schema, setSchema] = useState<SqlSchema | null>(null)
  const [diagram, setDiagram] = useState<Diagram | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [saveState, setSaveState] = useState<SaveState>('idle')

  // Структурные правки (таблицы/колонки/связи) сохраняем тяжёлым bulk-запросом,
  // а изменения холста (камера/зоны/линии) — лёгким обновлением только canvas.
  const structureDirtyRef = useRef(false)
  const canvasDirtyRef = useRef(false)
  const structureTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const canvasTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Актуальная схема для колбэков настроек без пересоздания коллбэка.
  const schemaRef = useRef<SqlSchema | null>(null)
  useEffect(() => {
    schemaRef.current = schema
  }, [schema])

  // Актуальная диаграмма для флаша при уходе — слушатели вешаются один раз.
  const diagramRef = useRef<Diagram | null>(null)
  useEffect(() => {
    diagramRef.current = diagram
  }, [diagram])

  useEffect(() => {
    let active = true
    setLoading(true)
    sqlApi
      .get(id)
      .then((data) => {
        if (!active) return
        setSchema(data)
        setDiagram(schemaToDiagram(data))
      })
      .catch((err) => {
        if (!active) return
        setError(err instanceof ApiError ? err.message : t('loadSchemaFailed'))
      })
      .finally(() => active && setLoading(false))

    return () => {
      active = false
    }
  }, [id])

  /** Структурная правка: планирует тяжёлое сохранение всей диаграммы. */
  const mutate = useCallback((updater: (prev: Diagram) => Diagram) => {
    setDiagram((prev) => (prev ? updater(prev) : prev))
    structureDirtyRef.current = true
  }, [])

  /** Правка холста: планирует лёгкое сохранение только canvas. */
  const mutateCanvas = useCallback((updater: (prev: Diagram) => Diagram) => {
    setDiagram((prev) => (prev ? updater(prev) : prev))
    canvasDirtyRef.current = true
  }, [])

  // Дебаунс-сохранение всей диаграммы (только при структурных изменениях).
  useEffect(() => {
    if (!diagram || !structureDirtyRef.current) return

    if (structureTimerRef.current) clearTimeout(structureTimerRef.current)
    structureTimerRef.current = setTimeout(async () => {
      setSaveState('saving')
      try {
        await sqlApi.saveDiagram(id, diagramToPayload(diagram))
        structureDirtyRef.current = false
        setSaveState('saved')
      } catch {
        setSaveState('error')
      }
    }, AUTOSAVE_DELAY)

    return () => {
      if (structureTimerRef.current) clearTimeout(structureTimerRef.current)
    }
  }, [diagram, id])

  // Лёгкое сохранение холста: камера, зоны и режим линий — без таблиц.
  useEffect(() => {
    if (!diagram || !canvasDirtyRef.current) return

    if (canvasTimerRef.current) clearTimeout(canvasTimerRef.current)
    canvasTimerRef.current = setTimeout(async () => {
      const current = schemaRef.current
      if (!current) return
      try {
        await sqlApi.update(id, {
          name: current.name,
          canvas: { ...diagram.canvas, lineMode: diagram.lineMode, zones: diagram.zones },
        })
        canvasDirtyRef.current = false
      } catch {
        // Молча — камера не критична, повторим при следующем изменении.
      }
    }, AUTOSAVE_DELAY)

    return () => {
      if (canvasTimerRef.current) clearTimeout(canvasTimerRef.current)
    }
  }, [diagram, id])

  // Флаш при уходе со страницы: отправляем незакоммиченное немедленно через
  // keepalive, чтобы не потерять последнее окно дебаунса. Условия — те же dirty-
  // флаги, что и у автосейва: структура и/или холст.
  const flush = useCallback(() => {
    const diagram = diagramRef.current
    const schema = schemaRef.current
    if (!diagram) return

    if (structureDirtyRef.current) {
      structureDirtyRef.current = false
      if (structureTimerRef.current) clearTimeout(structureTimerRef.current)
      void sqlApi.saveDiagram(id, diagramToPayload(diagram), { keepalive: true }).catch(() => {})
    }

    if (canvasDirtyRef.current && schema) {
      canvasDirtyRef.current = false
      if (canvasTimerRef.current) clearTimeout(canvasTimerRef.current)
      void sqlApi
        .update(
          id,
          {
            name: schema.name,
            canvas: { ...diagram.canvas, lineMode: diagram.lineMode, zones: diagram.zones },
          },
          { keepalive: true },
        )
        .catch(() => {})
    }
  }, [id])

  useFlushOnLeave(flush)

  const addTable = useCallback(
    (posX: number, posY: number, name?: string) => {
      mutate((prev) => {
        const safeName = uniqueTableName(
          name?.trim() || `table_${prev.tables.length + 1}`,
          prev.tables,
        )
        return { ...prev, tables: [...prev.tables, makeTable(posX, posY, safeName)] }
      })
    },
    [mutate],
  )

  const updateTable = useCallback(
    (uid: string, patch: Partial<SqlTable>) => {
      mutate((prev) => {
        // Имена таблиц не должны совпадать — при коллизии добавляем суффикс.
        const safePatch =
          patch.name !== undefined
            ? { ...patch, name: uniqueTableName(patch.name, prev.tables, uid) }
            : patch
        return {
          ...prev,
          tables: prev.tables.map((t) => (t.uid === uid ? { ...t, ...safePatch } : t)),
        }
      })
    },
    [mutate],
  )

  const moveTable = useCallback(
    (uid: string, posX: number, posY: number) => {
      mutate((prev) => ({
        ...prev,
        tables: prev.tables.map((t) =>
          t.uid === uid ? { ...t, pos_x: Math.round(posX), pos_y: Math.round(posY) } : t,
        ),
      }))
    },
    [mutate],
  )

  const removeTable = useCallback(
    (uid: string) => {
      mutate((prev) => {
        const table = prev.tables.find((t) => t.uid === uid)
        const columnUids = new Set(table?.columns.map((c) => c.uid))
        return {
          ...prev,
          tables: prev.tables.filter((t) => t.uid !== uid),
          relations: prev.relations.filter((r) => !columnUids.has(r.from) && !columnUids.has(r.to)),
        }
      })
    },
    [mutate],
  )

  const addColumn = useCallback(
    (tableUid: string) => {
      mutate((prev) => ({
        ...prev,
        tables: prev.tables.map((t) =>
          t.uid === tableUid ? { ...t, columns: withNewColumn(t.columns) } : t,
        ),
      }))
    },
    [mutate],
  )

  const updateColumn = useCallback(
    (tableUid: string, columnUid: string, patch: Partial<SqlColumn>) => {
      mutate((prev) => ({
        ...prev,
        tables: prev.tables.map((t) =>
          t.uid === tableUid
            ? {
                ...t,
                columns: t.columns.map((c) => (c.uid === columnUid ? { ...c, ...patch } : c)),
              }
            : t,
        ),
      }))
    },
    [mutate],
  )

  const removeColumn = useCallback(
    (tableUid: string, columnUid: string) => {
      mutate((prev) => ({
        ...prev,
        tables: prev.tables.map((t) =>
          t.uid === tableUid ? { ...t, columns: t.columns.filter((c) => c.uid !== columnUid) } : t,
        ),
        relations: prev.relations.filter((r) => r.from !== columnUid && r.to !== columnUid),
      }))
    },
    [mutate],
  )

  const reorderColumns = useCallback(
    (tableUid: string, fromIndex: number, toIndex: number) => {
      mutate((prev) => ({
        ...prev,
        tables: prev.tables.map((t) => {
          if (t.uid !== tableUid) return t
          const columns = [...t.columns]
          if (
            fromIndex < 0 ||
            toIndex < 0 ||
            fromIndex >= columns.length ||
            toIndex >= columns.length
          ) {
            return t
          }
          const [moved] = columns.splice(fromIndex, 1)
          columns.splice(toIndex, 0, moved)
          return { ...t, columns: columns.map((c, i) => ({ ...c, position: i })) }
        }),
      }))
    },
    [mutate],
  )

  const addRelation = useCallback(
    (fromColumnUid: string, toColumnUid: string) => {
      if (fromColumnUid === toColumnUid) return
      mutate((prev) => {
        const exists = prev.relations.some((r) => r.from === fromColumnUid && r.to === toColumnUid)
        if (exists) return prev

        // Находим обе колонки и их таблицы.
        let fromCol: SqlColumn | undefined
        let toCol: SqlColumn | undefined
        let toTable: SqlTable | undefined
        let fromTable: SqlTable | undefined
        for (const table of prev.tables) {
          for (const column of table.columns) {
            if (column.uid === fromColumnUid) {
              fromCol = column
              fromTable = table
            }
            if (column.uid === toColumnUid) {
              toCol = column
              toTable = table
            }
          }
        }
        if (!fromCol || !toCol || !toTable || !fromTable) return prev

        // Нельзя связывать первичный ключ с первичным ключом.
        if (fromCol.is_primary && toCol.is_primary) return prev

        const relation: DiagramRelation = {
          uid: newUid(),
          from: fromColumnUid,
          to: toColumnUid,
          type: 'one_to_many',
          on_delete: 'cascade',
          on_update: null,
        }

        // Внешний ключ переименовываем по конвенции и подгоняем тип под ссылку.
        const fkName = foreignKeyName(toTable.name, toCol.name)
        const fkType = toCol.type === 'id' ? 'bigInteger' : toCol.type

        return {
          ...prev,
          tables: prev.tables.map((t) =>
            t.uid === fromTable!.uid
              ? {
                  ...t,
                  columns: t.columns.map((c) =>
                    c.uid === fromColumnUid ? { ...c, name: fkName, type: fkType } : c,
                  ),
                }
              : t,
          ),
          relations: [...prev.relations, relation],
        }
      })
    },
    [mutate],
  )

  const removeRelation = useCallback(
    (relationUid: string) => {
      mutate((prev) => ({
        ...prev,
        relations: prev.relations.filter((r) => r.uid !== relationUid),
      }))
    },
    [mutate],
  )

  // Камера, зоны и режим линий — часть холста: лёгкое сохранение только canvas.
  const setCanvas = useCallback(
    (canvas: Diagram['canvas']) => {
      mutateCanvas((prev) => ({ ...prev, canvas }))
    },
    [mutateCanvas],
  )

  const setLineMode = useCallback(
    (mode: SqlLineMode) => {
      mutateCanvas((prev) => ({ ...prev, lineMode: mode }))
    },
    [mutateCanvas],
  )

  const addZone = useCallback(
    (zone: SqlZone) => {
      mutateCanvas((prev) => ({ ...prev, zones: [...prev.zones, zone] }))
    },
    [mutateCanvas],
  )

  const updateZone = useCallback(
    (uid: string, patch: Partial<SqlZone>) => {
      mutateCanvas((prev) => ({
        ...prev,
        zones: prev.zones.map((z) => (z.uid === uid ? { ...z, ...patch } : z)),
      }))
    },
    [mutateCanvas],
  )

  const removeZone = useCallback(
    (uid: string) => {
      mutateCanvas((prev) => ({ ...prev, zones: prev.zones.filter((z) => z.uid !== uid) }))
    },
    [mutateCanvas],
  )

  const updateSettings = useCallback(
    async (input: { name?: string }) => {
      const current = schemaRef.current
      if (!current) return
      const name = (input.name ?? current.name).trim()
      if (!name) return

      setSchema((prev) => (prev ? { ...prev, name } : prev))
      try {
        const updated = await sqlApi.update(id, { name })
        setSchema((prev) => (prev ? { ...prev, ...updated } : updated))
      } catch (err) {
        throw err instanceof ApiError ? err : new Error(t('saveSettingsFailed'))
      }
    },
    [id],
  )

  const saveNow = useCallback(async () => {
    const current = diagramRef.current
    if (!current || !structureDirtyRef.current) return
    if (structureTimerRef.current) clearTimeout(structureTimerRef.current)
    setSaveState('saving')
    try {
      await sqlApi.saveDiagram(id, diagramToPayload(current))
      structureDirtyRef.current = false
      setSaveState('saved')
    } catch (err) {
      setSaveState('error')
      throw err
    }
  }, [id])

  const reload = useCallback(async () => {
    const data = await sqlApi.get(id)
    setSchema(data)
    setDiagram((prev) => {
      const fresh = schemaToDiagram(data)
      return prev ? { ...fresh, canvas: prev.canvas } : fresh
    })
  }, [id])

  return {
    saveNow,
    reload,
    schema,
    diagram,
    loading,
    error,
    saveState,
    addTable,
    updateTable,
    moveTable,
    removeTable,
    addColumn,
    updateColumn,
    removeColumn,
    reorderColumns,
    addRelation,
    removeRelation,
    setCanvas,
    setLineMode,
    addZone,
    updateZone,
    removeZone,
    updateSettings,
  }
}
