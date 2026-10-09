import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../api/client'
import { sqlApi, type SqlSchemaInput } from '../api/sql'
import type { SqlSchema } from '../api/types'

interface UseSqlSchemas {
  schemas: SqlSchema[]
  loading: boolean
  error: string | null
  reload: () => Promise<void>
  createSchema: (input: SqlSchemaInput) => Promise<SqlSchema>
  importSchema: (input: { name: string; content: string }) => Promise<SqlSchema>
  deleteSchema: (id: number) => Promise<void>
}

export function useSqlSchemas(): UseSqlSchemas {
  const [schemas, setSchemas] = useState<SqlSchema[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setSchemas(await sqlApi.list())
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Не удалось загрузить схемы')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void reload()
  }, [reload])

  const createSchema = useCallback(
    async (input: SqlSchemaInput) => {
      const schema = await sqlApi.create(input)
      await reload()
      return schema
    },
    [reload],
  )

  const importSchema = useCallback(
    async (input: { name: string; content: string }) => {
      const schema = await sqlApi.import(input)
      await reload()
      return schema
    },
    [reload],
  )

  const deleteSchema = useCallback(
    async (id: number) => {
      await sqlApi.remove(id)
      await reload()
    },
    [reload],
  )

  return { schemas, loading, error, reload, createSchema, importSchema, deleteSchema }
}
