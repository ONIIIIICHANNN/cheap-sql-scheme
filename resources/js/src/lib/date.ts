import { plural, t } from './i18n'

/** «сегодня», «3 дня назад», «2 weeks ago». */
export function relativeDay(value: string): string {
  const then = new Date(value)
  if (Number.isNaN(then.getTime())) return ''

  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(new Date()) - startOf(then)) / 86_400_000)

  if (days <= 0) return t('today')
  if (days === 1) return t('yesterday')
  if (days < 7) return t('daysAgo', { n: days, days: plural(days, ['', ''], ['день', 'дня', 'дней']) })
  if (days < 14) return t('weekAgo')
  if (days < 31) {
    const weeks = Math.floor(days / 7)
    return t('weeksAgo', { n: weeks, weeks: plural(weeks, ['', ''], ['неделю', 'недели', 'недель']) })
  }
  const months = Math.floor(days / 30)
  return t('monthsAgo', { n: months, months: plural(months, ['', ''], ['месяц', 'месяца', 'месяцев']) })
}
