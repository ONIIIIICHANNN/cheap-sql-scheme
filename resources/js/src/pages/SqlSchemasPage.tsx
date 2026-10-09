import { useState } from 'react'
import type { SqlSchemaInput } from '../api/sql'
import { SqlSchemaFormModal } from '../components/sql/SqlSchemaFormModal'
import { CanvasList } from '../components/ui/CanvasList'
import { useToast } from '../components/ui/Toast'
import { useDeferredDelete } from '../hooks/useDeferredDelete'
import { useHotkeys } from '../hooks/useHotkeys'
import { useSqlSchemas } from '../hooks/useSqlSchemas'
import { fresh } from '../lib/ax'
import { t } from '../lib/i18n'

export function SqlSchemasPage() {
  const { notify } = useToast()
  const { schemas, loading, error, reload, createSchema, importSchema, deleteSchema } = useSqlSchemas()
  const [formOpen, setFormOpen] = useState(false)
  // Удалённая схема сразу прячется; запрос уходит, когда пропадёт «Вернуть».
  const [hidden, setHidden] = useState<number[]>([])
  const deferred = useDeferredDelete((id) => deleteSchema(id))

  useHotkeys({ n: () => setFormOpen(true), т: () => setFormOpen(true) }, !formOpen)

  async function submit(input: SqlSchemaInput) {
    const created = await createSchema(input)
    fresh.mark('sql', created.id)
  }

  const items = schemas
    .filter((s) => !hidden.includes(s.id))
    .map((s) => ({
      id: s.id,
      name: s.name,
      count: s.tables_count ?? 0,
      project: s.source === 'database',
      updatedAt: s.updated_at,
      createdAt: s.created_at,
    }))

  return (
    <>
      <CanvasList
        items={items}
        loading={loading}
        error={error}
        hrefFor={(id) => `/${id}`}
        onNew={() => setFormOpen(true)}
        onDelete={(x) => {
          setHidden((h) => [...h, x.id])
          const unhide = () => setHidden((h) => h.filter((id) => id !== x.id))
          deferred.schedule(x.id, () => {
            unhide()
            void reload()
          })
          notify(t('schemaDeleted', { name: x.name }), () => {
            deferred.cancel(x.id)
            unhide()
          })
        }}
      />

      <SqlSchemaFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        onSubmit={submit}
        onImport={async (input) => {
          const created = await importSchema(input)
          fresh.mark('sql', created.id)
          return created
        }}
      />
    </>
  )
}
