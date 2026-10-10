import { ArrowsClockwise, Database } from '@phosphor-icons/react'
import { useState } from 'react'
import type { SchemaDrift } from '../../api/types'
import { t } from '../../lib/i18n'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'
import { ChangeCard } from './CommitPanel'

interface DriftBannerProps {
  drift: SchemaDrift
  refreshing: boolean
  onRefresh: () => void
}

/**
 * «База изменилась вне редактора»: миграции выполнили в обход него или
 * поправили базу руками. Подробности — в том же виде, что окно коммита.
 */
export function DriftBanner({ drift, refreshing, onRefresh }: DriftBannerProps) {
  const [open, setOpen] = useState(false)
  const s = drift.summary

  return (
    <>
      <div
        role="status"
        className="absolute top-3 right-3 z-10 flex max-w-[calc(100%-24px)] items-center gap-3 rounded-xl border bg-surface py-2 pr-2 pl-3.5 text-[13px] shadow-[var(--shadow-md)]"
        style={{ borderColor: 'var(--lamp-ink)' }}
      >
        <Database size={17} className="shrink-0" style={{ color: 'var(--lamp-ink)' }} />
        <span className="min-w-0 truncate font-medium">{t('driftTitle')}</span>
        {s && (
          <span className="flex shrink-0 gap-2 font-mono text-[12px] tabular-nums">
            {s.added > 0 && <span style={{ color: 'var(--done)' }}>+{s.added}</span>}
            {s.changed > 0 && <span style={{ color: 'var(--lamp-ink)' }}>~{s.changed}</span>}
            {s.removed > 0 && <span style={{ color: 'var(--danger)' }}>−{s.removed}</span>}
          </span>
        )}
        <button
          onClick={() => setOpen(true)}
          className="shrink-0 rounded-lg px-2 py-1 text-muted underline-offset-2 hover:text-fg hover:underline"
        >
          {t('driftDetails')}
        </button>
        <Button size="sm" onClick={onRefresh} disabled={refreshing}>
          <ArrowsClockwise size={13} className={refreshing ? 'animate-spin' : undefined} />
          {t('driftRefresh')}
        </Button>
      </div>

      <Modal
        open={open}
        title={t('driftModalTitle')}
        onClose={() => setOpen(false)}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              {t('close')}
            </Button>
            <Button
              onClick={() => {
                setOpen(false)
                onRefresh()
              }}
              disabled={refreshing}
            >
              <ArrowsClockwise size={14} /> {t('driftRefresh')}
            </Button>
          </>
        }
      >
        <p className="mt-0 mb-3 text-[14px] leading-normal text-muted">{t('driftModalHint')}</p>
        <div className="flex max-h-[56vh] flex-col gap-2.5 overflow-y-auto pr-1">
          {(drift.changes ?? []).map((table) => (
            <ChangeCard key={table.uid} table={table} />
          ))}
        </div>
      </Modal>
    </>
  )
}
