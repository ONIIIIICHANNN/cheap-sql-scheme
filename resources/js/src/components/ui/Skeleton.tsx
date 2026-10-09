import type { CSSProperties } from 'react'
import { cn } from '../../lib/cn'

interface SkeletonProps {
  className?: string
  width?: CSSProperties['width']
  height?: CSSProperties['height']
}

/** Мерцающая заглушка на месте данных, пока они грузятся. */
export function Skeleton({ className, width, height = 14 }: SkeletonProps) {
  return <span aria-hidden className={cn('skeleton', className)} style={{ width, height }} />
}
