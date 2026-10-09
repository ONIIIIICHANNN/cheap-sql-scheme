import { Key, MagnifyingGlass, Sparkle, Table as TableIcon } from '@phosphor-icons/react'
import { useMemo, useState } from 'react'
import { cn } from '../../lib/cn'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'

export interface ForeignKeyTarget {
  value: string
  tableUid: string
  tableName: string
  columnName: string
  columnType: string
  /** Первичный или уникальный — на такие колонки обычно и ссылаются. */
  isKey: boolean
}

interface ForeignKeyModalProps {
  open: boolean
  columnName: string
  targets: ForeignKeyTarget[]
  currentTarget?: string | null
  onClose: () => void
  onConfirm: (targetUid: string) => void
  onCreateTable: () => void
}

type Sort = 'smart' | 'alpha'

/** Насколько таблица подходит колонке: user_id → users (2), → user_profiles (1). */
function fitScore(columnName: string, tableName: string): number {
  const base = columnName.toLowerCase().replace(/_?(id|uuid|key)$/, '')
  if (!base) return 0
  const t = tableName.toLowerCase()
  const singular = t
    .replace(/ies$/, 'y')
    .replace(/(ses|xes)$/, (m) => m.slice(0, -2))
    .replace(/s$/, '')
  if (t === base || singular === base || t === base + 's' || t === base + 'es') return 2
  return t.includes(base) || base.includes(singular) ? 1 : 0
}

/**
 * Выбор цели внешнего ключа: слева таблицы (поиск, сортировка «подходящие» —
 * по имени колонки, user_id → users), справа их колонки. По умолчанию
 * показываются только ключевые колонки — первичные и уникальные.
 */
export function ForeignKeyModal(props: ForeignKeyModalProps) {
  // Каждое открытие — с чистого листа: ключ сбрасывает поиск и выбор.
  return props.open ? <ForeignKeyDialog key={props.currentTarget ?? 'new'} {...props} /> : null
}

function ForeignKeyDialog({
  open,
  columnName,
  targets,
  currentTarget,
  onClose,
  onConfirm,
  onCreateTable,
}: ForeignKeyModalProps) {
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<Sort>('smart')
  const [keysOnly, setKeysOnly] = useState(true)

  const tables = useMemo(() => {
    const map = new Map<
      string,
      { uid: string; name: string; columns: ForeignKeyTarget[]; score: number }
    >()
    for (const t of targets) {
      const entry = map.get(t.tableUid) ?? {
        uid: t.tableUid,
        name: t.tableName,
        columns: [],
        score: fitScore(columnName, t.tableName),
      }
      entry.columns.push(t)
      map.set(t.tableUid, entry)
    }
    return [...map.values()]
  }, [targets, columnName])

  // Стартовый выбор: текущая связь, иначе ключ самой подходящей таблицы.
  const initial = useMemo(() => {
    if (currentTarget && targets.some((t) => t.value === currentTarget)) return currentTarget
    const best = [...tables].sort((a, b) => b.score - a.score)[0]
    return best?.columns.find((c) => c.isKey)?.value ?? best?.columns[0]?.value ?? null
  }, [currentTarget, targets, tables])

  const [selected, setSelected] = useState<string | null>(initial)
  const selectedTarget = targets.find((t) => t.value === selected) ?? null
  const [activeTable, setActiveTable] = useState<string | null>(selectedTarget?.tableUid ?? null)

  const q = query.trim().toLowerCase()
  const visibleColumns = (cols: ForeignKeyTarget[], tableName: string) =>
    cols.filter(
      (c) =>
        (!keysOnly || c.isKey || c.value === selected) &&
        (!q || tableName.toLowerCase().includes(q) || c.columnName.toLowerCase().includes(q)),
    )

  const visibleTables = tables
    .map((t) => ({ ...t, shown: visibleColumns(t.columns, t.name) }))
    .filter((t) => t.shown.length > 0)
    .sort((a, b) => (sort === 'smart' ? b.score - a.score : 0) || a.name.localeCompare(b.name))

  const current = visibleTables.find((t) => t.uid === activeTable) ?? visibleTables[0] ?? null
  const empty = targets.length === 0

  function confirm(value: string | null = selected) {
    if (!value) return
    onConfirm(value)
    onClose()
  }

  return (
    <Modal
      open={open}
      title="Внешний ключ"
      onClose={onClose}
      size="xl"
      footer={
        empty ? (
          <>
            <Button variant="secondary" onClick={onClose}>
              Отмена
            </Button>
            <Button onClick={onCreateTable}>Создать таблицу</Button>
          </>
        ) : (
          <>
            <span className="mr-auto min-w-0 self-center truncate text-[13px] text-muted">
              {selectedTarget ? (
                <>
                  {columnName} →{' '}
                  <span className="text-fg">
                    {selectedTarget.tableName}.{selectedTarget.columnName}
                  </span>
                </>
              ) : (
                'Выберите колонку'
              )}
            </span>
            <Button variant="secondary" onClick={onClose}>
              Отмена
            </Button>
            <Button disabled={!selected} onClick={() => confirm()}>
              Связать
            </Button>
          </>
        )
      }
    >
      {empty ? (
        <div className="flex flex-col items-center gap-3 px-4 py-6 text-center">
          <span className="grid size-12 place-items-center rounded-xl bg-surface shadow-[var(--shadow-sm)]">
            <TableIcon size={22} className="text-accent" />
          </span>
          <p className="text-[13px] leading-[1.55] text-neutral-400">
            Пока нет другой таблицы, на которую можно сослаться.
            <br />
            Создайте её — затем выберите колонку для связи.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <p className="m-0 text-[13px] text-neutral-500">
            Колонка <span className="font-mono text-fg">{columnName}</span> будет ссылаться на:
          </p>

          <div className="flex flex-wrap items-center gap-2">
            <label className="flex min-h-10 min-w-[220px] flex-1 items-center gap-2 rounded-[10px] border border-line px-3 text-muted focus-within:border-lamp-ink">
              <MagnifyingGlass size={15} />
              <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Escape' && query) {
                    e.stopPropagation()
                    setQuery('')
                  }
                }}
                placeholder="Таблица или колонка"
                aria-label="Поиск таблицы или колонки"
                className="min-w-0 flex-1 border-none bg-transparent text-[14px] text-fg outline-none"
              />
            </label>
            <div className="seg">
              <button
                className="seg-opt"
                aria-selected={sort === 'smart'}
                onClick={() => setSort('smart')}
              >
                Подходящие
              </button>
              <button
                className="seg-opt"
                aria-selected={sort === 'alpha'}
                onClick={() => setSort('alpha')}
              >
                А–Я
              </button>
            </div>
            <button
              onClick={() => setKeysOnly((v) => !v)}
              aria-pressed={keysOnly}
              title="Показывать только первичные и уникальные колонки"
              className={cn(
                'flex min-h-10 items-center gap-1.5 rounded-[10px] border px-3 text-[13px]',
                keysOnly
                  ? 'border-lamp-ink bg-tint text-lamp-ink'
                  : 'border-line text-muted hover:text-fg',
              )}
            >
              <Key size={14} />
              Только ключи
            </button>
          </div>

          {visibleTables.length === 0 ? (
            <p className="m-0 rounded-[12px] border border-dashed border-line p-5 text-[14px] text-muted">
              Ничего не нашлось.
              {keysOnly && ' Попробуйте выключить «Только ключи».'}
            </p>
          ) : (
            <div className="grid h-[min(52vh,440px)] grid-cols-1 gap-3 min-[640px]:grid-cols-[minmax(0,260px)_1fr]">
              <div className="no-scrollbar flex min-h-0 flex-col gap-0.5 overflow-y-auto rounded-[12px] border border-line p-1.5 max-[639px]:max-h-40">
                {visibleTables.map((t) => {
                  const on = current?.uid === t.uid
                  const hasSelected = t.columns.some((c) => c.value === selected)
                  return (
                    <button
                      key={t.uid}
                      onClick={() => setActiveTable(t.uid)}
                      className={cn(
                        'flex min-h-10 items-center gap-2 rounded-[9px] px-2.5 text-left text-[13.5px]',
                        on ? 'bg-tint text-fg' : 'text-neutral-300 hover:bg-tint/60',
                      )}
                    >
                      <TableIcon
                        size={14}
                        className={hasSelected ? 'text-lamp-ink' : 'text-muted'}
                      />
                      <span className="min-w-0 flex-1 truncate font-mono">{t.name}</span>
                      {sort === 'smart' && t.score === 2 && (
                        <Sparkle
                          size={13}
                          weight="fill"
                          className="shrink-0 text-lamp-ink"
                          aria-label="Подходит по имени"
                        />
                      )}
                      <span className="shrink-0 text-[12px] text-muted tabular-nums">
                        {t.shown.length}
                      </span>
                    </button>
                  )
                })}
              </div>

              <div className="no-scrollbar flex min-h-0 flex-col gap-1 overflow-y-auto">
                {current?.shown.map((c) => {
                  const on = selected === c.value
                  return (
                    <button
                      key={c.value}
                      type="button"
                      onClick={() => setSelected(c.value)}
                      onDoubleClick={() => confirm(c.value)}
                      title="Двойной клик — связать сразу"
                      className={cn(
                        'flex w-full items-center gap-2.5 rounded-[10px] border px-3 py-2.5 text-left text-[13.5px] transition-colors',
                        on
                          ? 'border-accent bg-accent/8 text-accent'
                          : 'border-divider text-neutral-300 hover:border-line-strong',
                      )}
                    >
                      <span
                        className={cn(
                          'grid size-4 shrink-0 place-items-center rounded-full border',
                          on ? 'border-accent' : 'border-line-strong',
                        )}
                      >
                        {on && <span className="size-2 rounded-full bg-accent" />}
                      </span>
                      <span className="min-w-0 flex-1 truncate font-mono">{c.columnName}</span>
                      {c.isKey && (
                        <Key size={13} className="shrink-0 text-lamp-ink" aria-label="Ключ" />
                      )}
                      <span className="shrink-0 font-mono text-[12px] text-muted">
                        {c.columnType}
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
