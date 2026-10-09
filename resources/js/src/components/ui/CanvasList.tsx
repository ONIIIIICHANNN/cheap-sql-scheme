import { Link } from 'react-router-dom'
import { fresh } from '../../lib/ax'
import { relativeDay } from '../../lib/date'
import { CardMenu, NewTile } from './ListTiles'
import { Skeleton } from './Skeleton'
import { Sticker, useFreshTick } from './Sticker'
import { BackToBoards } from './BackToBoards'
import { t, tablesCount } from '../../lib/i18n'

export interface CanvasItem {
  id: number
  name: string
  /** Таблиц в схеме. */
  count: number
  /** Схема базы самого проекта. */
  project: boolean
  updatedAt: string
  createdAt: string
}

interface Props {
  items: CanvasItem[]
  loading: boolean
  error: string | null
  hrefFor: (id: number) => string
  onNew: () => void
  onDelete: (item: CanvasItem) => void
}

const LAYOUTS = [
  [
    ['10%', '18%'],
    ['42%', '46%'],
    ['68%', '14%'],
  ],
  [
    ['14%', '40%'],
    ['46%', '12%'],
    ['70%', '48%'],
  ],
  [
    ['8%', '14%'],
    ['38%', '52%'],
  ],
]
const ROWS = [
  ['90%', '60%', '75%'],
  ['70%', '85%'],
  ['80%', '55%', '65%', '50%'],
]

/**
 * Список SQL-схем: полоса чисел, первая плитка «Новая схема», карточка
 * «обложка → название → ⋯ → мета».
 */
export function CanvasList({ items, loading, error, hrefFor, onNew, onDelete }: Props) {
  useFreshTick(items.some((x) => fresh.is('sql', x.id, x.createdAt)))

  return (
    <div className="flex flex-col gap-[26px]">
      <div>
        <BackToBoards />
        <h1 className="ax-title">{t('schemasTitle')}</h1>
        <p className="mt-2 max-w-[56ch] text-[16px] leading-normal text-pretty text-muted">
          {t('schemasSubtitle')}
        </p>
      </div>

      {loading ? (
        <div
          className="grid grid-cols-[repeat(auto-fill,minmax(min(300px,100%),1fr))] gap-4"
          aria-busy="true"
        >
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} height={250} className="rounded-[20px]" />
          ))}
        </div>
      ) : error ? (
        <p className="text-[15px] text-danger">{error}</p>
      ) : items.length === 0 ? (
        <div className="grid justify-items-center gap-3 px-4 py-14 text-center">
          <h2 className="m-0 font-num text-[24px] font-semibold">{t('noSchemasTitle')}</h2>
          <p className="m-0 max-w-[46ch] text-[16px] leading-normal text-pretty text-muted">
            {t('noSchemasText')}
          </p>
          <button onClick={onNew} className="btn btn-lamp min-h-12 px-[22px] text-[16px]">
            {t('createSchema')}
          </button>
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-end gap-x-10 gap-y-5">
            <div>
              <p className="m-0 text-[13px] text-muted">{t('totalSchemas')}</p>
              <p className="ax-num mt-1.5 mb-0">{items.length}</p>
            </div>
            <div>
              <p className="m-0 text-[13px] text-muted">{t('totalTables')}</p>
              <p className="ax-num mt-1.5 mb-0">{items.reduce((n, x) => n + x.count, 0)}</p>
            </div>
          </div>

          <div className="grid grid-cols-[repeat(auto-fill,minmax(min(300px,100%),1fr))] gap-4">
            <NewTile title={t('newSchema')} hint={t('newSchemaHint')} onClick={onNew} />
            {items.map((x, i) => (
              <Link
                key={x.id}
                to={hrefFor(x.id)}
                className="relative flex flex-col rounded-[20px] bg-surface text-fg shadow-[var(--shadow)] hover:text-fg hover:shadow-[var(--shadow-pop)]"
              >
                {fresh.is('sql', x.id, x.createdAt) && (
                  <Sticker k={`sql-${x.id}`} rot={-6}>
                    {t('freshSticker')}
                  </Sticker>
                )}
                <span className="relative block h-[132px] overflow-hidden rounded-t-[20px] bg-n800">
                  {/* Обложка условная, но не врёт: у пустой схемы — пусто, таблиц не больше, чем есть. */}
                  {x.count === 0 ? (
                    <span className="absolute inset-0 grid place-items-center text-[13px] text-muted">
                      {t('emptyCover')}
                    </span>
                  ) : (
                    LAYOUTS[i % 3].slice(0, x.count).map(([left, top], j) => (
                      <span
                        key={j}
                        className="absolute flex w-[74px] flex-col gap-[3px] rounded-[6px] bg-surface p-1.5 shadow-[0_0_0_1px_var(--line)]"
                        style={{ left, top }}
                      >
                        <span className="h-[5px] w-[70%] rounded-[3px] bg-lamp" />
                        {ROWS[(i + j) % 3].map((w, k) => (
                          <span
                            key={k}
                            className="h-1 rounded-[3px] bg-n700"
                            style={{ width: w }}
                          />
                        ))}
                      </span>
                    ))
                  )}
                </span>
                <span className="flex flex-col gap-2.5 px-5 pt-[18px] pb-5">
                  <span className="flex items-start gap-2.5">
                    <span className="min-w-0 flex-1 text-[18px] leading-[1.3] font-medium">
                      {x.name}
                    </span>
                    {x.project && (
                      <span className="shrink-0 rounded-full bg-tint px-2.5 py-[3px] text-[13px] text-lamp-ink">
                        {t('projectBadge')}
                      </span>
                    )}
                    <CardMenu label={t('delete')} onRemove={() => onDelete(x)} />
                  </span>
                  <span className="flex items-center gap-2.5 text-[14px] text-muted">
                    <span className="tabular-nums">
                      {tablesCount(x.count)}
                    </span>
                    <span className="ml-auto">{relativeDay(x.updatedAt)}</span>
                  </span>
                </span>
              </Link>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
