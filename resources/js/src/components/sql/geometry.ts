import type { SqlTable } from '../../api/types'

/** Размеры узла таблицы — общие для отрисовки и для расчёта якорей связей. */
export const TABLE_WIDTH = 224
export const HEADER_HEIGHT = 36
export const ROW_HEIGHT = 30

export interface Anchor {
  x: number
  y: number
  side: 'left' | 'right'
}

/** Вертикальная середина строки колонки в координатах холста. */
export function columnCenterY(table: SqlTable, columnUid: string): number {
  const index = table.columns.findIndex((c) => c.uid === columnUid)
  const safeIndex = index < 0 ? 0 : index
  return table.pos_y + HEADER_HEIGHT + safeIndex * ROW_HEIGHT + ROW_HEIGHT / 2
}

/**
 * Точка привязки линии к колонке. Сторона выбирается так, чтобы линия
 * выходила навстречу второй таблице — тогда она не пересекает узел.
 */
export function columnAnchor(table: SqlTable, columnUid: string, towardX: number): Anchor {
  const y = columnCenterY(table, columnUid)
  const tableCenterX = table.pos_x + TABLE_WIDTH / 2
  const side: 'left' | 'right' = towardX >= tableCenterX ? 'right' : 'left'
  return { x: side === 'right' ? table.pos_x + TABLE_WIDTH : table.pos_x, y, side }
}

/**
 * Гладкая горизонтальная кривая Безье между двумя якорями. Управляющие
 * точки уходят строго по горизонтали, поэтому линия входит в колонку ровно
 * и никогда не выглядит кривой.
 */
export function relationPath(from: Anchor, to: Anchor): string {
  const distance = Math.max(40, Math.abs(to.x - from.x) / 2)
  const c1x = from.x + (from.side === 'right' ? distance : -distance)
  const c2x = to.x + (to.side === 'right' ? distance : -distance)
  return `M ${from.x} ${from.y} C ${c1x} ${from.y}, ${c2x} ${to.y}, ${to.x} ${to.y}`
}

/**
 * Строгая ортогональная трасса между якорями: короткий «пенёк» от колонки,
 * затем прямые углы. Для режима «серьёзных» прямых линий.
 */
export function orthogonalPath(from: Anchor, to: Anchor): string {
  const stub = 18
  const sx = from.x + (from.side === 'right' ? stub : -stub)
  const tx = to.x + (to.side === 'right' ? stub : -stub)
  const midX = (sx + tx) / 2
  return [
    `M ${from.x} ${from.y}`,
    `L ${sx} ${from.y}`,
    `L ${midX} ${from.y}`,
    `L ${midX} ${to.y}`,
    `L ${tx} ${to.y}`,
    `L ${to.x} ${to.y}`,
  ].join(' ')
}

export function tableHeight(table: SqlTable): number {
  return HEADER_HEIGHT + table.columns.length * ROW_HEIGHT
}
