import React from 'react';
import { requireAdminUser } from '@/lib/admin/auth';
import AdminShell from './AdminShell';

export const dynamic = 'force-dynamic';

export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  const user = await requireAdminUser();
  const adminName = (user.user_metadata?.name as string) || 'Administrator';
  const adminEmail = user.email || '';

  return (
    <AdminShell adminName={adminName} adminEmail={adminEmail}>
      {children}
    </AdminShell>
  );
}
