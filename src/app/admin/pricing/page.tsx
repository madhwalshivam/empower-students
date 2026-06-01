import React from 'react';
import { Tag } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader } from '@/components/admin/ui';
import PricingClient from './PricingClient';

export const dynamic = 'force-dynamic';

export default async function AdminPricingPage() {
  await requireAdminUser();
  const db = createAdminClient();
  const { data } = await db.from('service_prices').select('service_key, label, price, is_active').order('price', { ascending: true });

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={Tag} title="Pricing" subtitle="Credit cost and availability for every service and pack." />
      <PricingClient initial={(data || []).map((r) => ({ ...r, price: r.price ?? 0, is_active: !!r.is_active }))} />
    </div>
  );
}
