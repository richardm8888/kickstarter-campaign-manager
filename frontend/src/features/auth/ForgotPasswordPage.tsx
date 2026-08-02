import { useState, type FormEvent } from 'react'
import { Link } from '@tanstack/react-router'
import { useMutation } from '@tanstack/react-query'
import { forgotPassword } from './api'
import { AuthLayout, FieldError } from './AuthLayout'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')

  const mutation = useMutation({ mutationFn: forgotPassword })

  const errors = mutation.error instanceof ApiError ? mutation.error.errors : {}

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate(email)
  }

  return (
    <AuthLayout title="Reset your password" subtitle="We'll email you a reset link">
      <Card>
        <CardContent className="p-5">
          {mutation.isSuccess ? (
            <p role="status" className="text-sm text-muted-foreground">
              {mutation.data.message}
            </p>
          ) : (
            <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="email">Email</Label>
                <Input
                  id="email"
                  type="email"
                  autoComplete="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
                <FieldError messages={errors.email} />
              </div>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? 'Sending…' : 'Send reset link'}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
      <p className="mt-4 text-center text-sm text-muted-foreground">
        <Link to="/login" className="font-medium text-foreground hover:underline">
          Back to sign in
        </Link>
      </p>
    </AuthLayout>
  )
}
