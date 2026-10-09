import {
  ArrowRight,
  CheckCircle,
  DownloadSimple,
  FloppyDisk,
  GitCommit,
  Table as TableIcon,
  Warning,
} from '@phosphor-icons/react'
import { useEffect, useState } from 'react'
import { ApiError } from '../../api/client'
import { sqlApi } from '../../api/sql'
import type { ChangeStatus, SchemaChanges } from '../../api/types'
import { cn } from '../../lib/cn'
import { relativeDay } from '../../lib/date'
import { t } from '../../lib/i18n'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'

interface CommitModalProps {
  open: boolean
  schemaId: number
  /** Дождаться сохранения правок — иначе сервер сравнит не то. */
  beforeLoad: () => Promise<void>
  onClose: () => void
  /** Коммит прошёл: базовый снимок сдвинулся, схему надо перечитать. */
  onCommitted: () => void
}

type Tab = 'changes' | 'migration'

/** Цвет и знак статуса: зелёный «+», красный «−», жёлтый «~», синий «→». */
const STATUS: Record<ChangeStatus, { sign: string; color: string; label: () => string }> = {
  added: { sign: '+', color: 'var(--done)', label: () => t('statusAdded') },
  removed: { sign: '−', color: 'var(--danger)', label: () => t('statusRemoved') },
  changed: { sign: '~', color: 'var(--lamp-ink)', label: () => t('statusChanged') },
  renamed: { sign: '→', color: '#7aa7d9', label: () => t('statusRenamed') },
}

function saveBlob(content: string, filename: string) {
  const url = URL.createObjectURL(new Blob([content], { type: 'text/plain' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/**
 * Окно коммита: что изменилось с прошлого раза (зелёным, красным, жёлтым,
 * синим), миграция только с этими изменениями и запись её в проект.
 */
export function CommitModal(props: CommitModalProps) {
  // Каждое открытие — заново: свежие изменения и пустое состояние.
  return props.open ? <CommitDialog {...props} /> : null
}

function CommitDialog({ open, schemaId, beforeLoad, onClose, onCommitted }: CommitModalProps) {
  const [data, setData] = useState<SchemaChanges | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<Tab>('changes')
  const [name, setName] = useState('')
  const [busy, setBusy] = useState<'write' | 'download' | null>(null)
  const [done, setDone] = useState<{ filename: string; path: string | null } | null>(null)

  useEffect(() => {
    let active = true
    beforeLoad()
      .then(() => sqlApi.changes(schemaId))
      .then((r) => {
        if (!active) return
        setData(r)
        setName(r.name)
      })
      .catch((err) => active && setError(err instanceof ApiError ? err.message : t('changesFailed')))
    return () => {
      active = false
    }
  }, [schemaId, beforeLoad])

  async function commit(write: boolean) {
    setBusy(write ? 'write' : 'download')
    setError(null)
    try {
      const r = await sqlApi.commit(schemaId, { name, write })
      if (!write) saveBlob(r.content, r.filename)
      setDone({ filename: r.filename, path: r.path })
      onCommitted()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('commitFailed'))
    } finally {
      setBusy(null)
    }
  }

  const validName = /^[a-z0-9_]+$/.test(name)

  const footer = done ? (
    <Button onClick={onClose}>{t('close')}</Button>
  ) : data && !data.empty ? (
    <>
      <label className="mr-auto flex min-w-[260px] flex-1 items-center gap-2 text-[13px] text-muted">
        <span className="shrink-0">{t('migrationName')}</span>
        <input
          value={name}
          onChange={(e) => setName(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
          className={cn('input h-9 min-w-0 flex-1 font-mono text-[12.5px]', !validName && 'border-danger')}
          aria-label={t('migrationName')}
        />
      </label>
      <Button
        variant="secondary"
        onClick={() => void commit(false)}
        disabled={!validName || busy !== null}
        loading={busy === 'download'}
        title={t('commitDownloadHint')}
      >
        <DownloadSimple size={14} /> {t('commitDownload')}
      </Button>
      {data.can_write && (
        <Button
          onClick={() => void commit(true)}
          disabled={!validName || busy !== null}
          loading={busy === 'write'}
        >
          <FloppyDisk size={14} /> {t('commitWrite')}
        </Button>
      )}
    </>
  ) : (
    <Button variant="secondary" onClick={onClose}>
      {t('close')}
    </Button>
  )

  return (
    <Modal open={open} title={t('commitTitle')} onClose={onClose} size="2xl" footer={footer}>
      {done ? (
        <div className="flex flex-col items-center gap-3 px-4 py-8 text-center">
          <CheckCircle size={44} weight="fill" style={{ color: 'var(--done)' }} />
          <p className="m-0 text-[17px] font-semibold">{t('committedTitle')}</p>
          <p className="m-0 max-w-[60ch] text-[14px] leading-normal text-muted">
            {done.path
              ? t('committedWritten', { file: done.filename, path: done.path })
              : t('committedDownloaded', { file: done.filename })}
          </p>
          <code className="rounded-lg bg-bg px-3 py-2 font-mono text-[13px]">php artisan migrate</code>
        </div>
      ) : error && !data ? (
        <p className="text-[14px] text-danger">{error}</p>
      ) : !data ? (
        <p className="text-[14px] text-muted">{t('changesLoading')}</p>
      ) : (
        <div className="flex flex-col gap-3.5">
          {data.pending.length > 0 && (
            <div
              role="alert"
              className="flex gap-2.5 rounded-xl border px-3.5 py-3 text-[13px] leading-normal"
              style={{ borderColor: 'var(--lamp-ink)', color: 'var(--lamp-ink)' }}
            >
              <Warning size={18} className="mt-px shrink-0" />
              <div className="min-w-0">
                <p className="m-0 font-medium">{t('pendingTitle', { n: data.pending.length })}</p>
                <ul className="my-1 pl-4 font-mono text-[12px]">
                  {data.pending.slice(0, 5).map((p) => (
                    <li key={p} className="truncate">
                      {p}
                    </li>
                  ))}
                  {data.pending.length > 5 && <li>…</li>}
                </ul>
                <p className="m-0 text-muted">{t('pendingHint')}</p>
              </div>
            </div>
          )}

          {data.empty ? (
            <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
              <GitCommit size={40} className="text-muted" />
              <p className="m-0 text-[17px] font-semibold">{t('noChangesTitle')}</p>
              <p className="m-0 max-w-[52ch] text-[14px] leading-normal text-muted">
                {t('noChangesText')}
              </p>
            </div>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2">
                <div className="seg">
                  <button
                    className="seg-opt"
                    aria-selected={tab === 'changes'}
                    onClick={() => setTab('changes')}
                  >
                    {t('tabChanges')}
                  </button>
                  <button
                    className="seg-opt"
                    aria-selected={tab === 'migration'}
                    onClick={() => setTab('migration')}
                  >
                    {t('tabMigration')}
                  </button>
                </div>
                <span className="ml-auto flex items-center gap-3 text-[13px] tabular-nums">
                  {data.summary.added > 0 && (
                    <span style={{ color: 'var(--done)' }}>+{data.summary.added}</span>
                  )}
                  {data.summary.changed > 0 && (
                    <span style={{ color: 'var(--lamp-ink)' }}>~{data.summary.changed}</span>
                  )}
                  {data.summary.removed > 0 && (
                    <span style={{ color: 'var(--danger)' }}>−{data.summary.removed}</span>
                  )}
                  <span className="text-muted">
                    {data.committed_at
                      ? t('lastCommit', { when: relativeDay(data.committed_at) })
                      : t('neverCommitted')}
                  </span>
                </span>
              </div>

              {tab === 'changes' ? (
                <div className="flex max-h-[56vh] flex-col gap-2.5 overflow-y-auto pr-1">
                  {data.changes.map((table) => (
                    <ChangeCard key={table.uid} table={table} />
                  ))}
                </div>
              ) : (
                <pre className="m-0 max-h-[56vh] overflow-auto rounded-lg bg-bg p-4 font-mono text-[12px] leading-[1.6] text-fg">
                  {data.migration}
                </pre>
              )}

              {data.required_without_default.length > 0 && (
                <p className="m-0 flex items-start gap-2 text-[13px] leading-normal" style={{ color: 'var(--lamp-ink)' }}>
                  <Warning size={15} className="mt-0.5 shrink-0" />
                  {t('requiredWarning', { columns: data.required_without_default.join(', ') })}
                </p>
              )}
              {data.changes.some((c) => c.status === 'removed' || c.items.some((i) => i.status === 'removed')) && (
                <p className="m-0 flex items-center gap-2 text-[13px]" style={{ color: 'var(--danger)' }}>
                  <Warning size={15} className="shrink-0" /> {t('dataLossWarning')}
                </p>
              )}
              {error && <p className="m-0 text-[13px] text-danger">{error}</p>}
            </>
          )}
        </div>
      )}
    </Modal>
  )
}

function StatusBadge({ status }: { status: ChangeStatus }) {
  const s = STATUS[status]
  return (
    <span
      className="shrink-0 rounded-full border px-2 py-[1px] text-[11.5px] font-medium"
      style={{ color: s.color, borderColor: s.color }}
    >
      {s.label()}
    </span>
  )
}

export function ChangeCard({ table }: { table: SchemaChanges['changes'][number] }) {
  const s = STATUS[table.status]
  return (
    <div
      className="overflow-hidden rounded-xl border border-line"
      style={{ borderLeft: `3px solid ${s.color}` }}
    >
      <div className="flex items-center gap-2 bg-[rgb(var(--ink)/0.03)] px-3.5 py-2.5">
        <TableIcon size={15} style={{ color: s.color }} />
        <span className="min-w-0 truncate font-mono text-[13.5px] font-semibold">
          {table.old_name ? (
            <>
              <span className="text-muted line-through">{table.old_name}</span>
              <ArrowRight size={12} className="mx-1.5 inline" />
              {table.name}
            </>
          ) : (
            table.name
          )}
        </span>
        <span className="ml-auto" />
        <StatusBadge status={table.status} />
      </div>

      {table.items.length > 0 && (
        <ul className="m-0 list-none divide-y divide-[var(--line)] p-0">
          {table.items.map((item, i) => {
            const st = STATUS[item.status]
            return (
              <li
                key={`${item.kind}-${item.name}-${i}`}
                className="grid grid-cols-[18px_minmax(120px,200px)_1fr] items-baseline gap-2 px-3.5 py-2 text-[13px]"
              >
                <span className="font-mono font-bold" style={{ color: st.color }}>
                  {st.sign}
                </span>
                <span className="min-w-0 truncate font-mono">
                  {item.kind === 'foreign' && <span className="text-muted">FK </span>}
                  {item.old_name ? (
                    <>
                      <span className="text-muted line-through">{item.old_name}</span>
                      <span className="text-muted"> → </span>
                      {item.name}
                    </>
                  ) : (
                    <span className={item.status === 'removed' ? 'text-muted line-through' : undefined}>
                      {item.name}
                    </span>
                  )}
                </span>
                <span className="min-w-0 font-mono text-[12px] leading-normal">
                  {item.status === 'changed' && item.before && item.after ? (
                    <>
                      <span style={{ color: 'var(--danger)' }} className="line-through opacity-80">
                        {item.before}
                      </span>
                      <ArrowRight size={11} className="mx-1.5 inline text-muted" />
                      <span style={{ color: 'var(--done)' }}>{item.after}</span>
                    </>
                  ) : (
                    <span className="text-muted">{item.after ?? item.before}</span>
                  )}
                </span>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
