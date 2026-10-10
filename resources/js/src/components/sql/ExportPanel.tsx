import { CaretDown, CaretRight, Check, Copy, DownloadSimple, FileCode } from '@phosphor-icons/react'
import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { ApiError } from '../../api/client'
import { sqlApi, type ExportResult, type SqlDumpDialect } from '../../api/sql'
import { cn } from '../../lib/cn'
import { t } from '../../lib/i18n'
import { Button } from '../ui/Button'

interface ExportPanelProps {
  schemaId: number
  /** Куда рисовать кнопки — подвал общего окна (см. MigrationsModal). */
  footer: HTMLElement | null
}

type Mode = 'single' | 'files' | 'sql'

const DIALECTS: { key: SqlDumpDialect; label: string }[] = [
  { key: 'mysql', label: 'MySQL / MariaDB' },
  { key: 'pgsql', label: 'PostgreSQL' },
  { key: 'sqlite', label: 'SQLite' },
]

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/**
 * Вся схема как есть — для нового проекта или другой базы: миграция Laravel
 * одним файлом, по файлу на таблицу (архивом) или .sql для MySQL, PostgreSQL
 * и SQLite. Только скачать или скопировать: в проект миграции попадают
 * коммитом, иначе таблицы создавались бы второй раз.
 */
export function ExportPanel({ schemaId, footer: footerEl }: ExportPanelProps) {
  const [result, setResult] = useState<ExportResult | null>(null)
  const [mode, setMode] = useState<Mode>('single')
  const [expanded, setExpanded] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [zipping, setZipping] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const [dump, setDump] = useState<{
    dialect: SqlDumpDialect
    filename: string
    content: string
  } | null>(null)
  const [dumpLoading, setDumpLoading] = useState(false)

  useEffect(() => {
    sqlApi
      .export(schemaId)
      .then((r) => {
        setResult(r)
        setExpanded(r.files[0]?.filename ?? null)
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : t('buildMigrationFailed')))
      .finally(() => setLoading(false))
  }, [schemaId])

  async function loadSql(dialect?: SqlDumpDialect) {
    setDumpLoading(true)
    setError(null)
    try {
      setDump(await sqlApi.exportSql(schemaId, dialect))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('buildSqlFailed'))
    } finally {
      setDumpLoading(false)
    }
  }

  async function copy() {
    const text = mode === 'sql' ? dump?.content : result?.content
    if (!text) return
    await navigator.clipboard.writeText(text)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  async function downloadZip() {
    if (!result) return
    setZipping(true)
    try {
      saveBlob(
        await sqlApi.exportZip(schemaId),
        result.filename.replace(/_migration\.php$/, '_migrations.zip'),
      )
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('buildZipFailed'))
    } finally {
      setZipping(false)
    }
  }

  const copyButton = (
    <Button
      variant="secondary"
      onClick={() => void copy()}
      disabled={mode === 'sql' ? !dump : !result}
    >
      {copied ? <Check size={14} /> : <Copy size={14} />}
      {copied ? t('copied') : t('copy')}
    </Button>
  )

  const footer =
    mode === 'sql' ? (
      <>
        {copyButton}
        <Button
          onClick={() =>
            dump && saveBlob(new Blob([dump.content], { type: 'application/sql' }), dump.filename)
          }
          disabled={!dump}
        >
          <DownloadSimple size={14} /> {t('downloadSql')}
        </Button>
      </>
    ) : mode === 'single' ? (
      <>
        {copyButton}
        <Button
          onClick={() =>
            result && saveBlob(new Blob([result.content], { type: 'text/plain' }), result.filename)
          }
          disabled={!result}
        >
          <DownloadSimple size={14} /> {t('downloadPhp')}
        </Button>
      </>
    ) : (
      <Button onClick={() => void downloadZip()} disabled={!result || zipping}>
        <DownloadSimple size={14} /> {zipping ? t('zipping') : t('downloadZip')}
      </Button>
    )

  return (
    <div className="flex flex-col gap-3">
      {footerEl && createPortal(footer, footerEl)}

      <p className="m-0 text-[13px] leading-normal text-muted">{t('fullSchemaHint')}</p>

      <div className="seg self-start">
        <button
          className="seg-opt"
          aria-selected={mode === 'single'}
          onClick={() => setMode('single')}
        >
          {t('modeSingle')}
        </button>
        <button
          className="seg-opt"
          aria-selected={mode === 'files'}
          onClick={() => setMode('files')}
        >
          {t('modeFiles')}
          {result && <span className="text-muted tabular-nums"> · {result.files.length}</span>}
        </button>
        <button
          className="seg-opt"
          aria-selected={mode === 'sql'}
          onClick={() => {
            setMode('sql')
            if (!dump) void loadSql()
          }}
        >
          {t('modeSql')}
        </button>
      </div>

      {mode === 'sql' && (
        <div className="flex flex-col gap-2.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-[13px] text-muted">{t('databaseLabel')}</span>
            <div className="seg">
              {DIALECTS.map((d) => (
                <button
                  key={d.key}
                  className="seg-opt"
                  aria-selected={dump?.dialect === d.key}
                  onClick={() => void loadSql(d.key)}
                >
                  {d.label}
                </button>
              ))}
            </div>
          </div>
          {dumpLoading ? (
            <p className="text-[13px] text-neutral-500">{t('buildingSql')}</p>
          ) : error ? (
            <p className="text-[13px] text-danger">{error}</p>
          ) : (
            dump && (
              <pre className="max-h-[52vh] overflow-auto rounded-lg bg-bg p-4 font-mono text-[12px] leading-[1.6] text-fg">
                {dump.content}
              </pre>
            )
          )}
        </div>
      )}

      {mode === 'sql' ? null : loading ? (
        <p className="text-[13px] text-neutral-500">{t('buildingMigrations')}</p>
      ) : error ? (
        <p className="text-[13px] text-danger">{error}</p>
      ) : !result ? null : mode === 'single' ? (
        <pre className="max-h-[52vh] overflow-auto rounded-lg bg-bg p-4 font-mono text-[12px] leading-[1.6] text-fg">
          {result.content}
        </pre>
      ) : (
        <div className="flex max-h-[52vh] flex-col gap-1.5 overflow-auto">
          <p className="m-0 text-[13px] leading-normal text-muted">{t('filesHint')}</p>
          {result.files.map((f) => {
            const open = expanded === f.filename
            return (
              <div key={f.filename} className="rounded-lg border border-line">
                <button
                  onClick={() => setExpanded(open ? null : f.filename)}
                  className={cn(
                    'flex w-full items-center gap-2 px-3 py-2 text-left font-mono text-[12.5px] hover:bg-tint',
                    open && 'bg-tint',
                  )}
                >
                  {open ? <CaretDown size={13} /> : <CaretRight size={13} />}
                  <FileCode size={15} className="text-lamp-ink" />
                  <span className="min-w-0 flex-1 truncate">{f.filename}</span>
                </button>
                {open && (
                  <pre className="m-0 max-h-[40vh] overflow-auto border-t border-line bg-bg p-4 font-mono text-[12px] leading-[1.6] text-fg">
                    {f.content}
                  </pre>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
