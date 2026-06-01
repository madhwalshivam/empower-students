'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { cfCreateOrder, isCashfreeConfigured } from '@/lib/cashfree/client';

export async function createTopupOrderAction(amount: number): Promise<{
  ok: boolean;
  error?: string;
  paymentSessionId?: string;
  orderId?: string;
  cashfreeEnv?: string;
}> {
  const supabase = await createClient();

  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { ok: false, error: 'Authentication required' };
  }

  if (amount < 10) {
    return { ok: false, error: 'Minimum top-up amount is ₹10.' };
  }

  if (!isCashfreeConfigured()) {
    return { ok: false, error: 'Payment gateway is currently not configured.' };
  }

  const supabaseAdmin = createAdminClient();

  // Create deterministic unique order ID
  const timestamp = Math.floor(Date.now() / 1000);
  const randomSuffix = Math.floor(Math.random() * 10000);
  const orderId = `ESI_${user.id.substring(0, 5)}_${timestamp}_${randomSuffix}`;

  // Insert payment order into database
  const { data: po, error: poErr } = await supabaseAdmin
    .from('payment_orders')
    .insert({
      parent_id: user.id,
      order_id: orderId,
      amount: amount,
      status: 'pending',
      credited: false,
    })
    .select('id')
    .single();

  if (poErr || !po) {
    console.error('Failed to create payment order record:', poErr);
    return { ok: false, error: 'Failed to initialize payment transaction.' };
  }

  // Construct return URL pointing to cashfree verification page
  const origin = process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000';
  const returnUrl = `${origin}/api/payment/verify?order_id=${orderId}`;

  try {
    const cfOrder = await cfCreateOrder({
      orderId,
      orderAmount: amount,
      customerId: user.id,
      customerName: user.user_metadata?.name || 'Parent',
      customerEmail: user.email || `${user.id.substring(0, 8)}@whatsapp.empowerstudents.in`,
      customerPhone: user.user_metadata?.phone || '+919999999999',
      returnUrl,
    });

    return {
      ok: true,
      paymentSessionId: cfOrder.payment_session_id,
      orderId,
      cashfreeEnv: process.env.CASHFREE_ENV || 'sandbox',
    };
  } catch (err: any) {
    console.error('Cashfree order creation error:', err);
    return { ok: false, error: err.message || 'Failed to communicate with payment processor.' };
  }
}
