import { plural } from './ax'

export function relativeDay(value: string): string {
  const then = new Date(value)
  if (Number.isNaN(then.getTime())) return ''

  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(new Date()) - startOf(then)) / 86_400_000)

  if (days <= 0) return 'сегодня'
  if (days === 1) return 'вчера'
  if (days < 7) return `${days} ${plural(days, 'день', 'дня', 'дней')} назад`
  if (days < 14) return 'неделю назад'
  if (days < 31) {
    const weeks = Math.floor(days / 7)
    return `${weeks} ${plural(weeks, 'неделю', 'недели', 'недель')} назад`
  }
  const months = Math.floor(days / 30)
  return `${months} ${plural(months, 'месяц', 'месяца', 'месяцев')} назад`
}
/** «только что», «12 мин назад», «3 ч назад» для сегодняшнего; дальше — как relativeDay. */
export function relativeTime(value: string): string {
  const then = new Date(value)
  if (Number.isNaN(then.getTime())) return ''
  const minutes = Math.floor((Date.now() - then.getTime()) / 60_000)
  const sameDay = new Date().toDateString() === then.toDateString()
  if (!sameDay || minutes < 0) return relativeDay(value)
  if (minutes < 1) return 'только что'
  if (minutes < 60) return `${minutes} мин назад`
  return `${Math.floor(minutes / 60)} ч назад`
}
