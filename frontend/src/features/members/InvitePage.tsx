import { useEffect } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import { acceptInvitation, getInvitation, rememberInvite } from './api'
import { AuthLayout } from '@/features/auth/AuthLayout'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ApiError, getToken } from '@/lib/api'

export function InvitePage() {
  const { token } = useParams({ strict: false }) as { token: string }
  const navigate = useNavigate()
  const signedIn = getToken() !== null
  const { data, error, isPending } = useQuery(getInvitation(token))

  // Remembered before sign-up or log-in, so either page can bring them back here.
  useEffect(() => {
    if (!signedIn) rememberInvite(token)
  }, [signedIn, token])

  const accept = useMutation({
    mutationFn: () => acceptInvitation(token),
    onSuccess: ({ project }) => {
      rememberInvite(null)
      navigate({ to: '/projects/$projectId', params: { projectId: String(project.id) } })
    },
  })

  if (isPending) {
    return <AuthLayout title="Opening your invite…" subtitle="">{null}</AuthLayout>
  }

  if (error || !data) {
    return (
      <AuthLayout
        title="This invite link doesn't work"
        subtitle={error instanceof ApiError ? error.message : 'It may have been mistyped.'}
      >
        <p className="text-center text-sm text-muted-foreground">
          Ask the person who invited you to send a new one.{' '}
          <Link to="/projects" className="font-medium text-foreground hover:underline">
            Go to your projects
          </Link>
        </p>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title={`Join ${data.project.name}`}
      subtitle={`${data.invited_by} has invited you to work on this launch together.`}
    >
      <Card>
        <CardContent className="flex flex-col gap-3 p-5">
          {signedIn ? (
            <>
              <Button disabled={accept.isPending} onClick={() => accept.mutate()}>
                {accept.isPending ? 'Joining…' : `Join ${data.project.name}`}
              </Button>
              {accept.error && (
                <p role="alert" className="text-xs text-destructive">
                  {accept.error instanceof ApiError ? accept.error.message : 'Something went wrong.'}
                </p>
              )}
            </>
          ) : (
            <>
              <p className="text-sm text-muted-foreground">
                Create an account (or log in if you already have one) and you'll come straight back here to join.
              </p>
              <Button onClick={() => navigate({ to: '/register' })}>Create an account</Button>
              <Button variant="outline" onClick={() => navigate({ to: '/login' })}>
                Log in
              </Button>
              <p className="text-xs text-muted-foreground">This invite is for {data.email}</p>
            </>
          )}
        </CardContent>
      </Card>
    </AuthLayout>
  )
}
