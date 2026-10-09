import { FileArrowUp, X } from '@phosphor-icons/react'
import { useRef, useState } from 'react'
import { ApiError } from '../../api/client'
import type { SqlSchemaInput } from '../../api/sql'
import type { SqlSchema } from '../../api/types'
import { Button } from '../ui/Button'
import { Input } from '../ui/Input'
import { Modal } from '../ui/Modal'
import { t } from '../../lib/i18n'

interface SqlSchemaFormModalProps {
  open: boolean
  onClose: () => void
  onSubmit: (input: SqlSchemaInput) => Promise<void>
  onImport: (input: { name: string; content: string }) => Promise<SqlSchema>
}

export function SqlSchemaFormModal({ open, onClose, onSubmit, onImport }: SqlSchemaFormModalProps) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Необязательный импорт: если выбран .sql-файл, схема создаётся из него
  // и таблицы автоматически раскладываются по сетке, а не в одну кучу.
  const [importFile, setImportFile] = useState<{ fileName: string; content: string } | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  function reset() {
    setName('')
    setImportFile(null)
    setError(null)
  }

  async function pickFile(file: File) {
    const content = await file.text()
    setImportFile({ fileName: file.name, content })
    if (!name.trim()) setName(file.name.replace(/\.(sql|php)$/i, ''))
  }

  async function submit() {
    if (!name.trim()) {
      setError(t('enterName'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      if (importFile) {
        await onImport({ name: name.trim(), content: importFile.content })
      } else {
        await onSubmit({ name: name.trim() })
      }
      reset()
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('createSchemaFailed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      title={t('newSchemaTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button onClick={submit} loading={busy}>
            {importFile ? t('createFromFile') : t('create')}
          </Button>
        </>
      }
    >
      <div className="space-y-3.5">
        <Input
          label={t('nameLabel')}
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t('schemaNamePlaceholder')}
          autoFocus
        />

        <div className="field">
          <label className="mb-1.5 block">{t('importOptional')}</label>
          {importFile ? (
            <div className="flex items-center gap-2 rounded-lg border border-accent/40 bg-accent/8 px-3 py-2.5 text-[12.5px]">
              <FileArrowUp size={16} className="shrink-0 text-accent" />
              <span className="min-w-0 flex-1 truncate">{importFile.fileName}</span>
              <button
                type="button"
                onClick={() => setImportFile(null)}
                className="grid size-5 place-items-center rounded-sm text-neutral-500 hover:text-danger"
                title={t('removeFile')}
              >
                <X size={13} />
              </button>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => fileRef.current?.click()}
              className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-divider py-2.5 text-[12.5px] text-neutral-500 transition-colors hover:border-accent-700 hover:text-accent"
            >
              <FileArrowUp size={15} /> {t('pickFile')}
            </button>
          )}
          <input
            ref={fileRef}
            type="file"
            accept=".sql,.php,text/plain"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) void pickFile(file)
              e.target.value = ''
            }}
          />
          {importFile && (
            <p className="mt-1.5 text-[11.5px] text-neutral-500">
              {t('importHint')}
            </p>
          )}
        </div>

        {error && <p className="text-[12.5px] text-danger">{error}</p>}
      </div>
    </Modal>
  )
}
