const TOKEN_KEY = 'klos.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) localStorage.removeItem(TOKEN_KEY)
  else localStorage.setItem(TOKEN_KEY, token)
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

/**
 * What to say when the response carried no message of its own.
 *
 * 502 and 504 do not come from the app at all — they are the proxy
 * saying it could not reach it, which is a different problem with a
 * different fix, and worth naming rather than flattening into
 * "something went wrong".
 */
function serverSideMessage(status: number): string {
  if (status === 502 || status === 503 || status === 504) {
    return `The app is not reachable right now (HTTP ${status}). It is usually a deploy still settling — try again in a minute.`
  }

  if (status >= 500) {
    return `The server hit an error (HTTP ${status}).`
  }

  return `Something went wrong (HTTP ${status}).`
}

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const response = await fetch(`/api${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (response.status === 401) {
    setToken(null)
    if (!location.pathname.startsWith('/login')) location.assign('/login')
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    throw new ApiError(
      response.status,
      // Falling back to a bare "something went wrong" hid a completely
      // unreachable API behind the same sentence as a validation error.
      // The status is the one thing always known, and it is the
      // difference between "the app threw" and "nothing answered".
      (data as { message?: string } | null)?.message ?? serverSideMessage(response.status),
      (data as { errors?: Record<string, string[]> } | null)?.errors ?? {},
    )
  }

  return data as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
}
