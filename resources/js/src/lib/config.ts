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
  user: { id: number | string; name: string } | null
  /** Куда ведёт «назад» со списка схем; null — кнопки нет. */
  backUrl: string | null
}

const fallback: CheapSqlSchemeConfig = {
  apiBase: '/sql-schemes/api',
  basePath: '/sql-schemes',
  csrfToken: '',
  user: null,
  backUrl: null,
}

export const config: CheapSqlSchemeConfig = {
  ...fallback,
  ...(window as unknown as { CheapSqlScheme?: Partial<CheapSqlSchemeConfig> }).CheapSqlScheme,
}
