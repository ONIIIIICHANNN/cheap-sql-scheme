import { ArrowLeft } from '@phosphor-icons/react'
import { config } from '../../lib/config'
import { t } from '../../lib/i18n'

/** «← Назад» над заголовком списка — обратно в приложение (config back_url). */
export function BackToBoards() {
  if (!config.backUrl) return null
  return (
    <a
      href={config.backUrl}
      className="-ml-2 mb-2 inline-flex min-h-9 items-center gap-1.5 rounded-[10px] px-2 text-[14px] text-muted hover:bg-tint hover:text-fg"
    >
      <ArrowLeft size={15} />
      {t('back')}
    </a>
  )
}
