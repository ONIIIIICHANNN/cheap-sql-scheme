import { useState } from 'react'
import { t } from '../../lib/i18n'
import { Modal } from '../ui/Modal'
import { CommitPanel } from './CommitPanel'
import { ExportPanel } from './ExportPanel'

export type MigrationsTab = 'changes' | 'full'

interface MigrationsModalProps {
  open: boolean
  schemaId: number
  /** Сколько изменений с последнего коммита — для вкладки. */
  changesCount: number
  /** Дождаться сохранения правок — иначе сервер сравнит не то. */
  beforeLoad: () => Promise<void>
  onClose: () => void
  onCommitted: () => void
}

/**
 * Всё про миграции в одном окне:
 *   «Changes» — что поменялось с прошлого коммита → миграция только с этим,
 *               в проект или скачать (единственный путь миграций в проект);
 *   «Full schema» — вся схема как есть, скачать или скопировать.
 */
export function MigrationsModal(props: MigrationsModalProps) {
  // Каждое открытие — с чистого листа: свежие изменения и сборки.
  return props.open ? <MigrationsDialog {...props} /> : null
}

function MigrationsDialog({
  open,
  schemaId,
  changesCount,
  beforeLoad,
  onClose,
  onCommitted,
}: MigrationsModalProps) {
  const [tab, setTab] = useState<MigrationsTab>('changes')
  // Подвал окна: каждая вкладка кладёт туда свои кнопки (порталом).
  const [footer, setFooter] = useState<HTMLDivElement | null>(null)

  return (
    <Modal
      open={open}
      title={t('migrationsTitle')}
      onClose={onClose}
      size="2xl"
      footer={<div ref={setFooter} className="contents" />}
    >
      <div className="seg mb-3.5 self-start">
        <button className="seg-opt" aria-selected={tab === 'changes'} onClick={() => setTab('changes')}>
          {changesCount > 0 ? `${t('tabCommit')} · ${changesCount}` : t('tabCommit')}
        </button>
        <button className="seg-opt" aria-selected={tab === 'full'} onClick={() => setTab('full')}>
          {t('tabFullSchema')}
        </button>
      </div>

      {tab === 'changes' ? (
        <CommitPanel
          schemaId={schemaId}
          footer={footer}
          beforeLoad={beforeLoad}
          onClose={onClose}
          onCommitted={onCommitted}
        />
      ) : (
        <ExportPanel schemaId={schemaId} footer={footer} />
      )}
    </Modal>
  )
}
