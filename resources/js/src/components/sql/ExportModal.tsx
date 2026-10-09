import { CaretDown, CaretRight, Check, Copy, DownloadSimple, FileCode } from '@phosphor-icons/react'
import { useEffect, useState } from 'react'
import { ApiError } from '../../api/client'
import { sqlApi, type ExportResult, type SqlDumpDialect } from '../../api/sql'
import { cn } from '../../lib/cn'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'

interface ExportModalProps {
  open: boolean
  schemaId: number
  onClose: () => void
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
 * Экспорт схемы: миграция Laravel одним файлом, миграции по файлу на таблицу
 * (в порядке зависимостей, архивом) или .sql-файл структуры для MySQL,
 * PostgreSQL или SQLite — его можно сразу выполнить в своей базе.
 */
export function ExportModal({ open, schemaId, onClose }: ExportModalProps) {
  const [result, setResult] = useState<ExportResult | null>(null)
  const [mode, setMode] = useState<Mode>('single')
  const [expanded, setExpanded] = useState<string | null>(null)
  const [loading, setLoading] = useState(open)
  const [zipping, setZipping] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const [dump, setDump] = useState<{
    dialect: SqlDumpDialect
    filename: string
    content: string
  } | null>(null)
  const [dumpLoading, setDumpLoading] = useState(false)

  async function loadSql(dialect?: SqlDumpDialect) {
    setDumpLoading(true)
    setError(null)
    try {
      setDump(await sqlApi.exportSql(schemaId, dialect))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Не удалось собрать .sql')
    } finally {
      setDumpLoading(false)
    }
  }

  // Каждое открытие — свежая сборка: сбрасываем состояние прямо в рендере.
  const [seenOpen, setSeenOpen] = useState(open)
  if (open !== seenOpen) {
    setSeenOpen(open)
    if (open) {
      setLoading(true)
      setError(null)
    }
  }

  useEffect(() => {
    if (!open) return
    sqlApi
      .export(schemaId)
      .then((r) => {
        setResult(r)
        setExpanded(r.files[0]?.filename ?? null)
      })
      .catch((err) =>
        setError(err instanceof ApiError ? err.message : 'Не удалось собрать миграцию'),
      )
      .finally(() => setLoading(false))
  }, [open, schemaId])

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
      setError(err instanceof ApiError ? err.message : 'Не удалось собрать архив')
    } finally {
      setZipping(false)
    }
  }

  return (
    <Modal
      open={open}
      title="Экспорт схемы"
      onClose={onClose}
      size="xl"
      footer={
        mode === 'sql' ? (
          <>
            <Button variant="secondary" onClick={() => void copy()} disabled={!dump}>
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? 'Скопировано' : 'Копировать'}
            </Button>
            <Button
              onClick={() =>
                dump &&
                saveBlob(new Blob([dump.content], { type: 'application/sql' }), dump.filename)
              }
              disabled={!dump}
            >
              <DownloadSimple size={14} /> Скачать .sql
            </Button>
          </>
        ) : mode === 'single' ? (
          <>
            <Button variant="secondary" onClick={() => void copy()} disabled={!result}>
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? 'Скопировано' : 'Копировать'}
            </Button>
            <Button
              onClick={() =>
                result &&
                saveBlob(new Blob([result.content], { type: 'text/plain' }), result.filename)
              }
              disabled={!result}
            >
              <DownloadSimple size={14} /> Скачать .php
            </Button>
          </>
        ) : (
          <Button onClick={() => void downloadZip()} disabled={!result || zipping}>
            <DownloadSimple size={14} /> {zipping ? 'Собираем…' : 'Скачать .zip'}
          </Button>
        )
      }
    >
      <div className="flex flex-col gap-3">
        <div className="seg self-start">
          <button
            className="seg-opt"
            aria-selected={mode === 'single'}
            onClick={() => setMode('single')}
          >
            Миграция Laravel
          </button>
          <button
            className="seg-opt"
            aria-selected={mode === 'files'}
            onClick={() => setMode('files')}
          >
            Файлами по таблицам
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
            SQL-файл
          </button>
        </div>

        {mode === 'sql' && (
          <div className="flex flex-col gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[13px] text-muted">База:</span>
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
              <p className="text-[13px] text-neutral-500">Собираем .sql…</p>
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
          <p className="text-[13px] text-neutral-500">Собираем миграции…</p>
        ) : error ? (
          <p className="text-[13px] text-danger">{error}</p>
        ) : !result ? null : mode === 'single' ? (
          <pre className="max-h-[60vh] overflow-auto rounded-lg bg-bg p-4 font-mono text-[12px] leading-[1.6] text-fg">
            {result.content}
          </pre>
        ) : (
          <div className="flex max-h-[60vh] flex-col gap-1.5 overflow-auto">
            <p className="m-0 text-[13px] leading-normal text-muted">
              По миграции на таблицу. Порядок — по связям: таблица, на которую ссылаются, создаётся
              раньше. Связи по кругу вынесены в последний файл. В архиве — папка migrations, её
              содержимое кладётся в database/migrations.
            </p>
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
    </Modal>
  )
}
