/**
 * Настройки, которые Laravel кладёт в window.CheapSqlScheme (см. app.blade.php):
 * куда слать запросы, на каком пути живёт интерфейс и кто вошёл.
 */
export interface CheapSqlSchemeConfig {
  /** Префикс API без завершающего слэша, например /sql-schemes/api. */
  apiBase: string
  /** Путь интерфейса без завершающего слэша — basename для роутера. */
  basePath: string
  csrfToken: string
  /** Куда ведёт «назад» со списка схем; null — кнопки нет. */
  backUrl: string | null
  /** Язык интерфейса. */
  locale: string
  /** Можно ли записать миграции прямо в проект (по умолчанию — только local). */
  canSaveMigrations: boolean
}

const fallback: CheapSqlSchemeConfig = {
  apiBase: '/sql-schemes/api',
  basePath: '/sql-schemes',
  csrfToken: '',
  backUrl: null,
  locale: 'en',
  canSaveMigrations: false,
}

export const config: CheapSqlSchemeConfig = {
  ...fallback,
  ...(window as unknown as { CheapSqlScheme?: Partial<CheapSqlSchemeConfig> }).CheapSqlScheme,
}
