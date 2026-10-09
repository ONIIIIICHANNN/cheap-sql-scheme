import { useEffect, useRef } from 'react'
import type { PointerEvent as ReactPointerEvent } from 'react'
import type { SqlZone } from '../../api/types'

interface ZoneLayerProps {
  zones: SqlZone[]
  zoom: number
  /** Выбранная зона — её правят в карточке «Зона» (имя, цвет, удаление). */
  selected: string | null
  onSelect: (uid: string | null) => void
  onUpdate: (uid: string, patch: Partial<SqlZone>) => void
}

type Drag =
  | { mode: 'move'; uid: string; startX: number; startY: number; origX: number; origY: number }
  | {
      mode: 'resize'
      uid: string
      startX: number
      startY: number
      origW: number
      origH: number
    }
  | null

const MIN_SIZE = 80

/**
 * Слой цветных зон. Лежит в трансформируемом контейнере холста и рисует
 * прямо в координатах диаграммы. Зону тянут за заголовок, растягивают за
 * угол; клик выбирает её — имя, цвет и удаление правятся в карточке «Зона».
 */
export function ZoneLayer({ zones, zoom, selected, onSelect, onUpdate }: ZoneLayerProps) {
  const dragRef = useRef<Drag>(null)

  useEffect(() => {
    function onMove(e: PointerEvent) {
      const drag = dragRef.current
      if (!drag) return
      const dx = (e.clientX - drag.startX) / zoom
      const dy = (e.clientY - drag.startY) / zoom
      if (drag.mode === 'move') {
        onUpdate(drag.uid, { x: Math.round(drag.origX + dx), y: Math.round(drag.origY + dy) })
      } else {
        onUpdate(drag.uid, {
          width: Math.round(Math.max(MIN_SIZE, drag.origW + dx)),
          height: Math.round(Math.max(MIN_SIZE, drag.origH + dy)),
        })
      }
    }
    function onUp() {
      dragRef.current = null
    }
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
    }
  }, [zoom, onUpdate])

  function startMove(e: ReactPointerEvent, zone: SqlZone) {
    e.stopPropagation()
    onSelect(zone.uid)
    dragRef.current = {
      mode: 'move',
      uid: zone.uid,
      startX: e.clientX,
      startY: e.clientY,
      origX: zone.x,
      origY: zone.y,
    }
  }

  function startResize(e: ReactPointerEvent, zone: SqlZone) {
    e.stopPropagation()
    onSelect(zone.uid)
    dragRef.current = {
      mode: 'resize',
      uid: zone.uid,
      startX: e.clientX,
      startY: e.clientY,
      origW: zone.width,
      origH: zone.height,
    }
  }

  return (
    <>
      {zones.map((zone) => {
        const isSelected = selected === zone.uid
        return (
          <div
            key={zone.uid}
            className="absolute rounded-xl border-2"
            style={{
              left: zone.x,
              top: zone.y,
              width: zone.width,
              height: zone.height,
              borderColor: zone.color,
              borderStyle: isSelected ? 'solid' : 'dashed',
              background: `color-mix(in srgb, ${zone.color} ${isSelected ? 18 : 12}%, transparent)`,
              boxShadow: isSelected
                ? `0 0 0 3px color-mix(in srgb, ${zone.color} 35%, transparent)`
                : undefined,
            }}
          >
            {/* Заголовок: тянуть — двигать, клик — выбрать. */}
            <div
              className="flex cursor-grab items-center gap-1.5 px-3 py-2 active:cursor-grabbing"
              onPointerDown={(e) => startMove(e, zone)}
              title="Тяните, чтобы двигать. Имя и цвет — в карточке «Зона»"
            >
              <span
                className="flex-1 truncate text-[14px] font-semibold"
                style={{ color: zone.color }}
              >
                {zone.label || 'Зона'}
              </span>
            </div>

            {/* Ручка изменения размера. */}
            <div
              onPointerDown={(e) => startResize(e, zone)}
              className="absolute right-0 bottom-0 size-5 cursor-nwse-resize"
              style={{
                background: `linear-gradient(135deg, transparent 50%, ${zone.color} 50%)`,
                borderBottomRightRadius: 10,
              }}
            />
          </div>
        )
      })}
    </>
  )
}
