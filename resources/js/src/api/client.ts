import { config } from '../lib/config'
import type { ApiEnvelope } from './types'
import { t } from '../lib/i18n'

/** Ошибка API с человекочитаемым сообщением и, при наличии, полями валидации. */
export class ApiError extends Error {
  status: number
  errors?: Record<string, string[]>

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: Method
  body?: unknown
  /**
   * keepalive: запрос переживает выгрузку страницы (unmount / beforeunload /
   * скрытие вкладки). Нужен, чтобы флаш несохранённых правок долетел до сервера,
   * когда обычный fetch уже отменяется. Ограничение — суммарно ~64 КБ.
   */
  keepalive?: boolean
}

/**
 * Заголовки запроса к Laravel: сессия едет cookie, а CSRF-токен — заголовком
 * (страницу отдаёт само приложение, токен лежит в её конфиге).
 */
export function authHeaders(): Record<string, string> {
  return {
    'X-Requested-With': 'XMLHttpRequest',
    ...(config.csrfToken ? { 'X-CSRF-TOKEN': config.csrfToken } : {}),
  }
}

export function apiUrl(path: string): string {
  return `${config.apiBase}${path}`
}

/**
 * Базовый запрос к API. Разворачивает конверт { data } и превращает ошибки
 * Laravel в ApiError.
 */
export async function request<T>(
  path: string,
  { method = 'GET', body, keepalive }: RequestOptions = {},
): Promise<T> {
  const response = await fetch(apiUrl(path), {
    method,
    keepalive,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...authHeaders(),
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  // 204 / пустое тело
  if (response.status === 204) {
    return undefined as T
  }

  const json = await response.json().catch(() => null)

  if (!response.ok) {
    const message = (json && (json.message as string)) || t('requestFailed', { status: response.status })
    const errors = json?.errors as Record<string, string[]> | undefined
    throw new ApiError(message, response.status, errors)
  }

  // Laravel-конверт { data, message, success }
  const envelope = json as ApiEnvelope<T>
  return envelope.data
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown, opts?: { keepalive?: boolean }) =>
    request<T>(path, { method: 'PUT', body, keepalive: opts?.keepalive }),
  delete: <T>(path: string, body?: unknown, opts?: { keepalive?: boolean }) =>
    request<T>(path, { method: 'DELETE', body, keepalive: opts?.keepalive }),
}
