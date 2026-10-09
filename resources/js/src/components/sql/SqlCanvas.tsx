import { useCallback, useEffect, useRef, useState } from 'react'
import type { PointerEvent as ReactPointerEvent } from 'react'
import type { SqlTable, SqlZone } from '../../api/types'
import type { Diagram } from '../../lib/sqlDiagram'
import { makeZone, ZONE_COLORS } from '../../lib/sqlDiagram'
import { columnCenterY, tableHeight, TABLE_WIDTH } from './geometry'
import { RelationLayer, type PendingLink } from './RelationLayer'
import { TableNode } from './TableNode'
import { ZoneLayer } from './ZoneLayer'

const MIN_ZOOM = 0.25
const MAX_ZOOM = 2.5

interface Rect {
  x: number
  y: number
  width: number
  height: number
}

interface DragItem {
  uid: string
  origX: number
  origY: number
}

interface LivePos {
  uid: string
  x: number
  y: number
}

type Interaction =
  | { mode: 'pan'; startX: number; startY: number; offsetX: number; offsetY: number }
  | { mode: 'drag'; items: DragItem[]; startX: number; startY: number }
  | { mode: 'link'; fromUid: string }
  | { mode: 'zone'; startX: number; startY: number }
  | { mode: 'marquee'; startX: number; startY: number }
  | null

interface SqlCanvasProps {
  diagram: Diagram
  selectedTable: string | null
  selectedRelation: string | null
  zoneMode: boolean
  zoneDrawArmed: boolean
  selectMode: boolean
  onSelectTable: (uid: string | null) => void
  onSelectRelation: (uid: string | null) => void
  onMoveTable: (uid: string, x: number, y: number) => void
  onAddRelation: (from: string, to: string) => void
  onCanvasChange: (canvas: Diagram['canvas']) => void
  onAddZone: (zone: SqlZone) => void
  onUpdateZone: (uid: string, patch: Partial<SqlZone>) => void
  selectedZone: string | null
  onSelectZone: (uid: string | null) => void
  onZoneDrawn: () => void
  /** Пометки изменений по uid таблицы (см. lib/changes). */
  changeMarks?: Map<string, string>
}

/** Пересекаются ли два прямоугольника. */
function intersects(a: Rect, b: Rect): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y
}

export function SqlCanvas({
  diagram,
  selectedTable,
  selectedRelation,
  zoneMode,
  zoneDrawArmed,
  selectMode,
  onSelectTable,
  onSelectRelation,
  onMoveTable,
  onAddRelation,
  onCanvasChange,
  onAddZone,
  onUpdateZone,
  selectedZone,
  onSelectZone,
  onZoneDrawn,
  changeMarks,
}: SqlCanvasProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [view, setView] = useState(diagram.canvas)
  // Размер вьюпорта для отсечения невидимых таблиц (виртуализация холста).
  const [size, setSize] = useState(() => ({
    w: typeof window !== 'undefined' ? window.innerWidth : 1280,
    h: typeof window !== 'undefined' ? window.innerHeight : 800,
  }))
  const [pending, setPending] = useState<PendingLink | null>(null)
  const [linkFrom, setLinkFrom] = useState<string | null>(null)
  const [draftZone, setDraftZone] = useState<Rect | null>(null)
  const [marquee, setMarquee] = useState<Rect | null>(null)
  const [selectedUids, setSelectedUids] = useState<Set<string>>(new Set())
  // Позиции перетаскиваемых таблиц держатся локально (без глобального
  // состояния) до отпускания — это и даёт плавность на больших схемах.
  const [dragPositions, setDragPositions] = useState<LivePos[] | null>(null)

  // Кадровые буферы: копим в рефы, применяем в состояние раз в кадр.
  const rafRef = useRef<number | null>(null)
  const pendingViewRef = useRef<typeof view | null>(null)
  const pendingPosRef = useRef<LivePos[] | null>(null)
  const pendingMarqueeRef = useRef<Rect | null>(null)

  const interactionRef = useRef<Interaction>(null)
  const viewRef = useRef(view)
  useEffect(() => {
    viewRef.current = view
  }, [view])

  // Свежие значения для стабильных обработчиков — чтобы memo-узлы таблиц не
  // перерисовывались из-за меняющихся ссылок на колбэки/массивы.
  const tablesRef = useRef(diagram.tables)
  tablesRef.current = diagram.tables
  const zonesRef = useRef(diagram.zones)
  zonesRef.current = diagram.zones
  const selectedUidsRef = useRef(selectedUids)
  selectedUidsRef.current = selectedUids
  const onSelectTableRef = useRef(onSelectTable)
  onSelectTableRef.current = onSelectTable
  const zoneModeRef = useRef(zoneMode)
  zoneModeRef.current = zoneMode
  const zoneDrawArmedRef = useRef(zoneDrawArmed)
  zoneDrawArmedRef.current = zoneDrawArmed
  const selectModeRef = useRef(selectMode)
  selectModeRef.current = selectMode
  const onZoneDrawnRef = useRef(onZoneDrawn)
  onZoneDrawnRef.current = onZoneDrawn
  const onSelectZoneRef = useRef(onSelectZone)
  onSelectZoneRef.current = onSelectZone

  // Применить накопленное за кадр (пан/перетаскивание/рамка выделения).
  const scheduleFlush = useCallback(() => {
    if (rafRef.current !== null) return
    rafRef.current = requestAnimationFrame(() => {
      rafRef.current = null
      if (pendingViewRef.current) {
        setView(pendingViewRef.current)
        pendingViewRef.current = null
      }
      if (pendingPosRef.current) setDragPositions(pendingPosRef.current)
      if (pendingMarqueeRef.current) setMarquee(pendingMarqueeRef.current)
    })
  }, [])

  // Следим за размером холста, чтобы правильно отсекать невидимое.
  useEffect(() => {
    const el = containerRef.current
    if (!el) return
    const apply = () => setSize({ w: el.clientWidth, h: el.clientHeight })
    apply()
    const ro = new ResizeObserver(apply)
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  // Начальная камера из сохранённого состояния (только при монтировании).
  const initedRef = useRef(false)
  useEffect(() => {
    if (!initedRef.current) {
      setView(diagram.canvas)
      initedRef.current = true
    }
  }, [diagram.canvas])

  const toCanvas = useCallback((clientX: number, clientY: number) => {
    const rect = containerRef.current?.getBoundingClientRect()
    const v = viewRef.current
    const left = rect?.left ?? 0
    const top = rect?.top ?? 0
    return {
      x: (clientX - left - v.offsetX) / v.zoom,
      y: (clientY - top - v.offsetY) / v.zoom,
    }
  }, [])

  // Зум колесом к точке под курсором. Нативный слушатель — чтобы preventDefault.
  useEffect(() => {
    const el = containerRef.current
    if (!el) return

    const onWheel = (e: WheelEvent) => {
      e.preventDefault()
      const rect = el.getBoundingClientRect()
      const v = viewRef.current
      const factor = e.deltaY < 0 ? 1.1 : 1 / 1.1
      const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, v.zoom * factor))
      const cx = e.clientX - rect.left
      const cy = e.clientY - rect.top
      const offsetX = cx - (cx - v.offsetX) * (zoom / v.zoom)
      const offsetY = cy - (cy - v.offsetY) * (zoom / v.zoom)
      const next = { zoom, offsetX, offsetY }
      setView(next)
      onCanvasChange(next)
    }

    el.addEventListener('wheel', onWheel, { passive: false })
    return () => el.removeEventListener('wheel', onWheel)
  }, [onCanvasChange])

  // Единый обработчик движения/отпускания для всех режимов взаимодействия.
  useEffect(() => {
    const onMove = (e: PointerEvent) => {
      const it = interactionRef.current
      if (!it) return

      if (it.mode === 'pan') {
        pendingViewRef.current = {
          zoom: viewRef.current.zoom,
          offsetX: it.offsetX + (e.clientX - it.startX),
          offsetY: it.offsetY + (e.clientY - it.startY),
        }
        scheduleFlush()
      } else if (it.mode === 'drag') {
        const zoom = viewRef.current.zoom
        const dx = (e.clientX - it.startX) / zoom
        const dy = (e.clientY - it.startY) / zoom
        pendingPosRef.current = it.items.map((i) => ({
          uid: i.uid,
          x: i.origX + dx,
          y: i.origY + dy,
        }))
        scheduleFlush()
      } else if (it.mode === 'link') {
        const from = anchorForColumn(tablesRef.current, it.fromUid)
        const end = toCanvas(e.clientX, e.clientY)
        if (from) setPending({ x1: from.x, y1: from.y, x2: end.x, y2: end.y })
      } else if (it.mode === 'zone') {
        const end = toCanvas(e.clientX, e.clientY)
        setDraftZone({
          x: Math.min(it.startX, end.x),
          y: Math.min(it.startY, end.y),
          width: Math.abs(end.x - it.startX),
          height: Math.abs(end.y - it.startY),
        })
      } else if (it.mode === 'marquee') {
        const end = toCanvas(e.clientX, e.clientY)
        pendingMarqueeRef.current = {
          x: Math.min(it.startX, end.x),
          y: Math.min(it.startY, end.y),
          width: Math.abs(end.x - it.startX),
          height: Math.abs(end.y - it.startY),
        }
        scheduleFlush()
      }
    }

    const onUp = (e: PointerEvent) => {
      const it = interactionRef.current
      interactionRef.current = null
      if (rafRef.current !== null) {
        cancelAnimationFrame(rafRef.current)
        rafRef.current = null
      }

      if (it?.mode === 'pan') {
        const next = pendingViewRef.current
        if (next) {
          setView(next)
          viewRef.current = next
          pendingViewRef.current = null
        }
        onCanvasChange(viewRef.current)
      }

      if (it?.mode === 'drag') {
        const list = pendingPosRef.current
        if (list) list.forEach((p) => onMoveTable(p.uid, p.x, p.y))
        pendingPosRef.current = null
        setDragPositions(null)
      }

      if (it?.mode === 'link') {
        const target = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-column-uid]')
        const toUid = target?.getAttribute('data-column-uid')
        if (toUid && toUid !== it.fromUid) onAddRelation(it.fromUid, toUid)
        setPending(null)
        setLinkFrom(null)
      }

      if (it?.mode === 'zone') {
        const end = toCanvas(e.clientX, e.clientY)
        const x = Math.min(it.startX, end.x)
        const y = Math.min(it.startY, end.y)
        const width = Math.abs(end.x - it.startX)
        const height = Math.abs(end.y - it.startY)
        // Слишком маленькое пятно — считаем это кликом, не создаём зону.
        if (width > 40 && height > 40) {
          const color = ZONE_COLORS[zonesRef.current.length % ZONE_COLORS.length]
          const zone = makeZone({
            x: Math.round(x),
            y: Math.round(y),
            width: Math.round(width),
            height: Math.round(height),
            color,
          })
          onAddZone(zone)
          // Новая зона сразу выбрана — карточка «Зона» открыта, можно назвать.
          onSelectZoneRef.current(zone.uid)
        }
        setDraftZone(null)
        onZoneDrawnRef.current()
      }

      if (it?.mode === 'marquee') {
        const end = toCanvas(e.clientX, e.clientY)
        const rect: Rect = {
          x: Math.min(it.startX, end.x),
          y: Math.min(it.startY, end.y),
          width: Math.abs(end.x - it.startX),
          height: Math.abs(end.y - it.startY),
        }
        const hits = new Set<string>()
        for (const table of tablesRef.current) {
          const box: Rect = {
            x: table.pos_x,
            y: table.pos_y,
            width: TABLE_WIDTH,
            height: tableHeight(table),
          }
          if (intersects(rect, box)) hits.add(table.uid)
        }
        pendingMarqueeRef.current = null
        setMarquee(null)
        setSelectedUids(hits)
      }
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current)
    }
  }, [onAddRelation, onAddZone, onCanvasChange, onMoveTable, scheduleFlush, toCanvas])

  function startBackground(e: ReactPointerEvent) {
    // Только по фону, не по узлам.
    if (e.target !== e.currentTarget) return
    // Гасим нативное выделение/перетаскивание браузера.
    e.preventDefault()
    onSelectRelation(null)
    onSelectZone(null)

    // Рисование зоны — только после нажатия кнопки «создать» (armed).
    if (zoneModeRef.current && zoneDrawArmedRef.current) {
      const start = toCanvas(e.clientX, e.clientY)
      interactionRef.current = { mode: 'zone', startX: start.x, startY: start.y }
      setDraftZone({ x: start.x, y: start.y, width: 0, height: 0 })
      return
    }

    // Режим выделения — рамка по фону.
    if (selectModeRef.current) {
      onSelectTable(null)
      setSelectedUids(new Set())
      const start = toCanvas(e.clientX, e.clientY)
      interactionRef.current = { mode: 'marquee', startX: start.x, startY: start.y }
      setMarquee({ x: start.x, y: start.y, width: 0, height: 0 })
      return
    }

    // Обычный клик по фону — снять выделение и панорамировать.
    onSelectTable(null)
    setSelectedUids(new Set())
    interactionRef.current = {
      mode: 'pan',
      startX: e.clientX,
      startY: e.clientY,
      offsetX: view.offsetX,
      offsetY: view.offsetY,
    }
  }

  // Стабильные обработчики для memo-узлов: ссылки не меняются между рендерами.
  const handleSelectTable = useCallback((uid: string) => {
    onSelectTableRef.current(uid)
    setSelectedUids(new Set([uid]))
  }, [])

  const handleHeaderPointerDown = useCallback((e: ReactPointerEvent, uid: string) => {
    e.stopPropagation()
    const selected = selectedUidsRef.current
    // Если тянем одну из нескольких выделенных — двигаем всю группу.
    const group = selected.has(uid) && selected.size > 1 ? [...selected] : [uid]
    if (group.length === 1) {
      onSelectTableRef.current(uid)
      setSelectedUids(new Set([uid]))
    }
    const items: DragItem[] = []
    for (const u of group) {
      const table = tablesRef.current.find((t) => t.uid === u)
      if (table) items.push({ uid: u, origX: table.pos_x, origY: table.pos_y })
    }
    interactionRef.current = { mode: 'drag', items, startX: e.clientX, startY: e.clientY }
  }, [])

  const handleColumnLinkPointerDown = useCallback((e: ReactPointerEvent, columnUid: string) => {
    e.stopPropagation()
    interactionRef.current = { mode: 'link', fromUid: columnUid }
    setLinkFrom(columnUid)
    const from = anchorForColumn(tablesRef.current, columnUid)
    if (from) setPending({ x1: from.x, y1: from.y, x2: from.x, y2: from.y })
  }, [])

  // Во время перетаскивания подменяем позиции у активных таблиц, не трогая
  // глобальное состояние (плавность + без ререндера редактора).
  let renderTables = diagram.tables
  if (dragPositions) {
    const byUid = new Map(dragPositions.map((p) => [p.uid, p]))
    renderTables = diagram.tables.map((t) => {
      const p = byUid.get(t.uid)
      return p ? { ...t, pos_x: p.x, pos_y: p.y } : t
    })
  }

  // Виртуализация: рисуем только таблицы, попадающие во вьюпорт (+ запас).
  // На больших схемах (сотни таблиц) это кардинально снижает число узлов DOM.
  const MARGIN = 400
  const left = (-view.offsetX - MARGIN) / view.zoom
  const top = (-view.offsetY - MARGIN) / view.zoom
  const right = (size.w - view.offsetX + MARGIN) / view.zoom
  const bottom = (size.h - view.offsetY + MARGIN) / view.zoom
  const draggingUids = dragPositions ? new Set(dragPositions.map((p) => p.uid)) : null
  const visibleTables = renderTables.filter((t) => {
    if (draggingUids?.has(t.uid)) return true
    return (
      t.pos_x < right &&
      t.pos_x + TABLE_WIDTH > left &&
      t.pos_y < bottom &&
      t.pos_y + tableHeight(t) > top
    )
  })

  const crosshair = (zoneMode && zoneDrawArmed) || selectMode

  return (
    <div
      ref={containerRef}
      className={
        'relative h-full w-full touch-none overflow-hidden bg-bg select-none [background-image:radial-gradient(rgb(var(--ink)/0.09)_1px,transparent_1px)] [background-size:22px_22px]' +
        (crosshair ? ' cursor-crosshair' : '')
      }
      style={{ backgroundPosition: `${view.offsetX}px ${view.offsetY}px` }}
      onPointerDown={startBackground}
      onDragStart={(e) => e.preventDefault()}
    >
      <div
        className="absolute top-0 left-0 origin-top-left"
        style={{ transform: `translate(${view.offsetX}px, ${view.offsetY}px) scale(${view.zoom})` }}
      >
        {/* Зоны лежат под таблицами и видны только в режиме зон. */}
        {zoneMode && (
          <ZoneLayer
            zones={diagram.zones}
            zoom={view.zoom}
            selected={selectedZone}
            onSelect={onSelectZone}
            onUpdate={onUpdateZone}
          />
        )}

        {zoneMode && draftZone && (
          <div
            className="pointer-events-none absolute rounded-xl border-2 border-dashed border-accent bg-accent/8"
            style={{
              left: draftZone.x,
              top: draftZone.y,
              width: draftZone.width,
              height: draftZone.height,
            }}
          />
        )}

        {marquee && (
          <div
            className="pointer-events-none absolute rounded-[4px] border border-accent bg-accent/10"
            style={{
              left: marquee.x,
              top: marquee.y,
              width: marquee.width,
              height: marquee.height,
            }}
          />
        )}

        {/* В режиме зон таблицы и связи бледнеют и не перехватывают клики. */}
        <div className={zoneMode ? 'pointer-events-none opacity-45' : undefined}>
          <RelationLayer
            tables={renderTables}
            relations={diagram.relations}
            selected={selectedRelation}
            onSelect={onSelectRelation}
            pending={pending}
            lineMode={diagram.lineMode}
          />

          {visibleTables.map((table) => (
            <TableNode
              key={table.uid}
              table={table}
              selected={selectedTable === table.uid || selectedUids.has(table.uid)}
              linkingFrom={linkFrom}
              onSelect={handleSelectTable}
              onHeaderPointerDown={handleHeaderPointerDown}
              onColumnLinkPointerDown={handleColumnLinkPointerDown}
              mark={changeMarks?.get(table.uid)}
            />
          ))}
        </div>
      </div>
    </div>
  )
}

/** Точка на правом крае колонки в координатах холста (старт линии связи). */
function anchorForColumn(tables: SqlTable[], columnUid: string) {
  const table = tables.find((t) => t.columns.some((c) => c.uid === columnUid))
  if (!table) return null
  return { x: table.pos_x + TABLE_WIDTH, y: columnCenterY(table, columnUid) }
}
