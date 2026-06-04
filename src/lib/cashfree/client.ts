import { createAdminClient } from '@/lib/supabase/admin';

const CASHFREE_APP_ID = process.env.CASHFREE_APP_ID || '';
const CASHFREE_SECRET_KEY = process.env.CASHFREE_SECRET_KEY || '';
const CASHFREE_ENV = process.env.CASHFREE_ENV || 'sandbox'; // sandbox or production
const CASHFREE_API_VERSION = '2023-08-01';

// Commission a partner earns on every wallet top-up made by a parent they
// referred. 15% by default; override with PARTNER_TOPUP_RATE (e.g. 0.20 = 20%).
const TOPUP_COMMISSION_RATE = Number(process.env.PARTNER_TOPUP_RATE) || 0.15;

export function isCashfreeConfigured(): boolean {
  return !!CASHFREE_APP_ID && !!CASHFREE_SECRET_KEY;
}

function getBaseUrl(): string {
  return CASHFREE_ENV === 'production'
    ? 'https://api.cashfree.com/pg'
    : 'https://sandbox.cashfree.com/pg';
}

async function cfRequest(method: string, endpoint: string, body?: any): Promise<any> {
  const url = `${getBaseUrl()}${endpoint}`;
  const headers = {
    'Content-Type': 'application/json',
    'x-api-version': CASHFREE_API_VERSION,
    'x-client-id': CASHFREE_APP_ID,
    'x-client-secret': CASHFREE_SECRET_KEY,
  };

  const response = await fetch(url, {
    method: method.toUpperCase(),
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const rawText = await response.text();
  let json: any = null;
  try {
    json = JSON.parse(rawText);
  } catch {
    // ignore
  }

  if (!response.ok) {
    const errorMsg = json?.message || rawText || 'Cashfree request failed';
    throw new Error(`Cashfree error (HTTP ${response.status}): ${errorMsg}`);
  }

  return json;
}

export async function cfCreateOrder(params: {
  orderId: string;
  orderAmount: number;
  customerId: string;
  customerName: string;
  customerEmail: string;
  customerPhone: string;
  returnUrl: string;
}): Promise<any> {
  if (!isCashfreeConfigured()) {
    throw new Error('Cashfree credentials are not configured.');
  }

  const payload = {
    order_id: params.orderId,
    order_amount: params.orderAmount,
    order_currency: 'INR',
    customer_details: {
      customer_id: params.customerId,
      customer_name: params.customerName.slice(0, 100),
      customer_email: params.customerEmail.slice(0, 100),
      customer_phone: params.customerPhone.slice(0, 15),
    },
    order_meta: {
      return_url: params.returnUrl,
    },
    order_note: 'Empower Students Wallet Topup',
  };

  return await cfRequest('POST', '/orders', payload);
}

export async function cfGetOrderStatus(orderId: string): Promise<any> {
  if (!isCashfreeConfigured()) {
    throw new Error('Cashfree credentials are not configured.');
  }
  return await cfRequest('GET', `/orders/${encodeURIComponent(orderId)}`);
}

export function calculateCredits(amount: number): { base: number; bonus: number; total: number } {
  let bonus = 0;
  if (amount >= 1000) bonus = 200;
  else if (amount >= 500) bonus = 75;
  else if (amount >= 250) bonus = 25;

  return {
    base: amount,
    bonus,
    total: amount + bonus,
  };
}

export async function creditOrderIfPaid(orderId: string): Promise<{
  status: 'credited' | 'already_credited' | 'pending' | 'failed' | 'verify_error' | 'unknown_order';
  newBalance?: number;
  error?: string;
}> {
  const supabase = createAdminClient();

  // Look up payment order in DB
  const { data: po, error: poErr } = await supabase
    .from('payment_orders')
    .select('*')
    .eq('order_id', orderId)
    .maybeSingle();

  if (poErr || !po) {
    return { status: 'unknown_order' };
  }

  if (po.credited) {
    return { status: 'already_credited' };
  }

  let cfOrder: any;
  try {
    cfOrder = await cfGetOrderStatus(orderId);
  } catch (err: any) {
    return { status: 'verify_error', error: err.message };
  }

  const cfStatus = cfOrder.order_status;

  if (cfStatus === 'PAID') {
    // Calculate total credits (including bonuses)
    const { total: creditAmount } = calculateCredits(Number(po.amount));

    // Fetch parent profile to update credits
    const { data: parent } = await supabase
      .from('parents')
      .select('credits')
      .eq('id', po.parent_id)
      .single();

    const previousCredits = parent?.credits || 0;
    const nextCredits = previousCredits + creditAmount;

    // Update parent credits
    await supabase
      .from('parents')
      .update({ credits: nextCredits })
      .eq('id', po.parent_id);

    // Record wallet ledger transaction entry
    const { data: ledgerRow } = await supabase
      .from('wallet_ledger')
      .insert({
        parent_id: po.parent_id,
        amount: creditAmount,
        balance_after: nextCredits,
        service_key: 'wallet_topup',
        ref_id: po.id,
        reason: `Top-up via Cashfree (order ${orderId})`,
      })
      .select('id')
      .single();

    // Mark payment order as completed and credited
    await supabase
      .from('payment_orders')
      .update({
        status: 'success',
        credited: true,
        completed_at: new Date().toISOString(),
        raw_response: JSON.stringify(cfOrder).substring(0, 5000),
      })
      .eq('id', po.id);

    // Partner commission: if this parent was referred by a partner, that partner
    // earns TOPUP_COMMISSION_RATE (15%) of the top-up amount as a pending payout.
    // This runs only here — inside the one-time "transition to credited" block —
    // so a top-up is never double-commissioned. Wrapped in try/catch so a payout
    // hiccup can never undo the (already-completed) wallet credit.
    try {
      const { data: parentRow } = await supabase
        .from('parents')
        .select('partner_id')
        .eq('id', po.parent_id)
        .maybeSingle();

      const partnerId = parentRow?.partner_id;
      if (partnerId) {
        const { data: partner } = await supabase
          .from('partners')
          .select('status')
          .eq('id', partnerId)
          .maybeSingle();

        // Only active partners earn commission.
        if (partner && partner.status === 'active') {
          const gross = Number(po.amount); // top-up amount in INR
          const partnerAmount = Math.round(gross * TOPUP_COMMISSION_RATE * 100) / 100;

          await supabase.from('partner_payouts').insert({
            partner_id: partnerId,
            parent_id: po.parent_id,
            wallet_ledger_id: ledgerRow?.id ?? null,
            service_key: 'wallet_topup',
            gross_amount: gross,
            partner_amount: partnerAmount,
            share_rate_used: TOPUP_COMMISSION_RATE,
            status: 'pending',
          });
        }
      }
    } catch (err) {
      console.error('[creditOrderIfPaid] partner commission error:', err);
    }

    return { status: 'credited', newBalance: nextCredits };
  }

  if (cfStatus === 'ACTIVE') {
    return { status: 'pending' };
  }

  // Mark payment order as failed
  await supabase
    .from('payment_orders')
    .update({
      status: 'failed',
      raw_response: JSON.stringify(cfOrder).substring(0, 5000),
    })
    .eq('id', po.id);

  return { status: 'failed' };
}
