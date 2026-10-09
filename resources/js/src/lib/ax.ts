/*
  «Новая!» — наклейка на только что созданной схеме. Если сервер отдал
  created_at, считаем по нему, иначе по отметке в localStorage.
*/
const FRESH_KEY = 'cheap-sql-scheme-fresh'
const FRESH_TTL = 10 * 60 * 1000

function freshMap(): Record<string, number> {
  try {
    return JSON.parse(localStorage.getItem(FRESH_KEY) ?? '{}') as Record<string, number>
  } catch {
    return {}
  }
}

export const fresh = {
  mark(scope: string, id: number | string): void {
    const m = freshMap()
    const now = Date.now()
    for (const k of Object.keys(m)) if (now - m[k] > FRESH_TTL) delete m[k]
    m[`${scope}:${id}`] = now
    try {
      localStorage.setItem(FRESH_KEY, JSON.stringify(m))
    } catch {
      // без хранилища наклейка просто не переживёт перезагрузку
    }
  },
  is(scope: string, id: number | string, createdAt?: string | null): boolean {
    const t = freshMap()[`${scope}:${id}`] ?? (createdAt ? Date.parse(createdAt) : NaN)
    return Number.isFinite(t) && Date.now() - t < FRESH_TTL
  },
}

/** Склонение: plural(3, 'день', 'дня', 'дней') → «дня». */
export function plural(n: number, one: string, few: string, many: string): string {
  const m10 = Math.abs(n) % 10
  const m100 = Math.abs(n) % 100
  if (m10 === 1 && m100 !== 11) return one
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few
  return many
}

/** «3 таблицы», «1 день». */
export function pl(n: number, one: string, few: string, many: string): string {
  return `${n.toLocaleString('ru-RU')} ${plural(n, one, few, many)}`
}
