import type { ReactNode } from 'react'
import { Rocket } from 'lucide-react'

export function AuthLayout({ title, subtitle, children }: {
  title: string
  subtitle: string
  children: ReactNode
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Rocket className="h-5 w-5" aria-hidden />
          </span>
          <div>
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  )
}

export function FieldError({ messages }: { messages?: string[] }) {
  if (!messages?.length) return null
  return (
    <p role="alert" className="text-xs text-destructive">
      {messages[0]}
    </p>
  )
}
