import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getStripeProducts, setStripeVipProducts } from './api'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * Picks which Stripe products count as a VIP upgrade. Buyers of those
 * products become VIP subscribers and join the VIP email group; anything
 * else is ordinary revenue.
 */
export function StripeProductPicker({ projectId }: { projectId: string }) {
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getStripeProducts(projectId))
  const [selected, setSelected] = useState<string[]>([])

  useEffect(() => {
    if (data) setSelected(data.vip_product_ids)
  }, [data])

  const mutation = useMutation({
    mutationFn: () => setStripeVipProducts(projectId, selected),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'stripe-products'] })
    },
  })

  if (isPending) return <Skeleton className="h-16" />
  if (!data?.connected) return null

  if (data.error) {
    return (
      <p role="alert" className="mt-4 text-xs text-destructive">
        Couldn't load your products: {data.error}
      </p>
    )
  }

  const toggle = (id: string) =>
    setSelected((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    )

  const changed =
    selected.length !== data.vip_product_ids.length ||
    selected.some((id) => !data.vip_product_ids.includes(id))

  return (
    <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4">
      <Label>VIP products</Label>
      <p className="text-xs text-muted-foreground">
        Buying one of these makes someone a VIP and adds them to your VIP email group. Everything else is
        counted as revenue only.
      </p>

      {data.products.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          No active products found in Stripe. Create one there, then reload this page.
        </p>
      ) : (
        <ul className="flex flex-col gap-1.5">
          {data.products.map((product) => (
            <li key={product.id}>
              <label className="flex items-center gap-2.5 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-input accent-[color:var(--primary)]"
                  checked={selected.includes(product.id)}
                  onChange={() => toggle(product.id)}
                />
                {product.name}
                <code className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                  {product.id}
                </code>
              </label>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-1 flex items-center gap-3">
        <Button size="sm" disabled={!changed || mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save'}
        </Button>
        {mutation.isSuccess && !changed && (
          <span role="status" className="text-xs text-muted-foreground">
            {mutation.data.message}
          </span>
        )}
      </div>
    </div>
  )
}
