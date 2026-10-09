import { memo, useMemo } from 'react'
import type { SqlLineMode, SqlTable } from '../../api/types'
import type { DiagramRelation } from '../../lib/sqlDiagram'
import { columnAnchor, orthogonalPath, relationPath, TABLE_WIDTH } from './geometry'

export interface PendingLink {
  x1: number
  y1: number
  x2: number
  y2: number
}

interface RelationLayerProps {
  tables: SqlTable[]
  relations: DiagramRelation[]
  selected: string | null
  onSelect: (uid: string | null) => void
  pending?: PendingLink | null
  lineMode?: SqlLineMode
}

/**
 * Слой связей. Лежит в трансформируемом контейнере холста, поэтому рисует
 * прямо в координатах диаграммы. overflow: visible нужен, чтобы линии за
 * пределами формального вьюпорта SVG всё равно отображались.
 */
function RelationLayerInner({
  tables,
  relations,
  selected,
  onSelect,
  pending,
  lineMode = 'smooth',
}: RelationLayerProps) {
  const tableByColumn = useMemo(() => {
    const map = new Map<string, SqlTable>()
    for (const table of tables) {
      for (const column of table.columns) map.set(column.uid, table)
    }
    return map
  }, [tables])

  return (
    <svg
      className="pointer-events-none absolute top-0 left-0 overflow-visible"
      width={1}
      height={1}
    >
      {relations.map((relation) => {
        const fromTable = tableByColumn.get(relation.from)
        const toTable = tableByColumn.get(relation.to)
        if (!fromTable || !toTable) return null

        const from = columnAnchor(fromTable, relation.from, toTable.pos_x + TABLE_WIDTH / 2)
        const to = columnAnchor(toTable, relation.to, fromTable.pos_x + TABLE_WIDTH / 2)
        const d = lineMode === 'straight' ? orthogonalPath(from, to) : relationPath(from, to)
        const isSelected = selected === relation.uid

        return (
          <g key={relation.uid} className="pointer-events-auto">
            {/* Широкая невидимая дорожка — по ней удобно попадать курсором. */}
            <path
              d={d}
              fill="none"
              stroke="transparent"
              strokeWidth={14}
              className="cursor-pointer"
              onClick={(e) => {
                e.stopPropagation()
                onSelect(isSelected ? null : relation.uid)
              }}
            />
            <path
              d={d}
              fill="none"
              stroke={isSelected ? 'var(--color-accent)' : 'rgb(var(--ink) / 0.45)'}
              strokeWidth={isSelected ? 2.5 : 1.75}
            />
            {/* Точка у первичного ключа (референс) и «лапка» у внешнего ключа. */}
            <circle cx={to.x} cy={to.y} r={4} fill="var(--color-accent)" />
            <circle
              cx={from.x}
              cy={from.y}
              r={3}
              fill="var(--color-bg)"
              stroke="rgb(var(--ink) / 0.55)"
              strokeWidth={1.5}
            />
          </g>
        )
      })}

      {pending && (
        <path
          d={`M ${pending.x1} ${pending.y1} C ${pending.x1 + 60} ${pending.y1}, ${
            pending.x2 - 60
          } ${pending.y2}, ${pending.x2} ${pending.y2}`}
          fill="none"
          stroke="var(--color-accent)"
          strokeWidth={2}
          strokeDasharray="5 4"
        />
      )}
    </svg>
  )
}

export const RelationLayer = memo(RelationLayerInner)
