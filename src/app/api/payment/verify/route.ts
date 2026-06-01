import { NextRequest, NextResponse } from 'next/server';
import { creditOrderIfPaid } from '@/lib/cashfree/client';

export const dynamic = 'force-dynamic';

export async function GET(request: NextRequest) {
  const { searchParams } = new URL(request.url);
  const orderId = searchParams.get('order_id');

  if (!orderId) {
    return NextResponse.redirect(new URL('/wallet?status=failed&error=missing_order_id', request.url));
  }

  try {
    const result = await creditOrderIfPaid(orderId);

    if (result.status === 'credited' || result.status === 'already_credited') {
      return NextResponse.redirect(new URL(`/wallet?status=success&order_id=${orderId}`, request.url));
    } else {
      return NextResponse.redirect(
        new URL(`/wallet?status=failed&error=${result.status}&order_id=${orderId}`, request.url)
      );
    }
  } catch (err: any) {
    console.error('Error during order verification callback:', err);
    return NextResponse.redirect(
      new URL(`/wallet?status=failed&error=verification_error&order_id=${orderId}`, request.url)
    );
  }
}
