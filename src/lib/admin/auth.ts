import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';

/**
 * Server-side guard for every admin page. Redirects non-admins away and
 * returns the authenticated admin user. Privileged reads in admin pages use
 * the service-role client, gated by this check.
 */
export async function requireAdminUser() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect('/login');
  if (user.user_metadata?.role !== 'admin') redirect('/dashboard');
  return user;
}
