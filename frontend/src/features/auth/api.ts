import { api, setToken } from '@/lib/api'
import type { User } from '@/lib/types'

interface AuthResponse {
  user: User
  token: string
}

export async function register(input: {
  name: string
  email: string
  password: string
  password_confirmation: string
}): Promise<User> {
  const { user, token } = await api.post<AuthResponse>('/auth/register', input)
  setToken(token)
  return user
}

export async function login(input: { email: string; password: string }): Promise<User> {
  const { user, token } = await api.post<AuthResponse>('/auth/login', input)
  setToken(token)
  return user
}

export async function logout(): Promise<void> {
  try {
    await api.post('/auth/logout')
  } finally {
    setToken(null)
  }
}

export function forgotPassword(email: string): Promise<{ message: string }> {
  return api.post<{ message: string }>('/auth/forgot-password', { email })
}

export function me(): Promise<{ user: User }> {
  return api.get<{ user: User }>('/auth/me')
}
