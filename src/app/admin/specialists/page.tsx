import React from 'react';
import { Stethoscope } from 'lucide-react';
import { requireAdminUser } from '@/lib/admin/auth';
import { createAdminClient } from '@/lib/supabase/admin';
import { AdminPageHeader, Card } from '@/components/admin/ui';
import SpecialistManager from '../dashboard/SpecialistManager';

export const dynamic = 'force-dynamic';

export default async function AdminSpecialistsPage() {
  await requireAdminUser();
  const db = createAdminClient();
  const { data: specialists } = await db.from('specialists').select('*').order('order_no', { ascending: true });

  return (
    <div className="space-y-6 animate-fade-in">
      <AdminPageHeader icon={Stethoscope} title="Specialists" subtitle="Clinicians shown on the Home page and the Our Panel page." />
      <Card className="p-6">
        <SpecialistManager initialSpecialists={specialists || []} />
      </Card>
    </div>
  );
}
