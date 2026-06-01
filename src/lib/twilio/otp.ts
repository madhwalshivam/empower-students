export async function sendOtpMessage(
  phone: string,
  code: string,
  verifyUrl: string
): Promise<{ ok: boolean; message: string }> {
  const mode = process.env.OTP_MODE || 'twilio_wa';
  if (mode === 'demo') {
    console.log(`[OTP demo] ${phone} -> ${code}`);
    return { ok: true, message: 'demo mode' };
  }

  const sid = process.env.TWILIO_SID;
  const token = process.env.TWILIO_AUTH_TOKEN;
  const from = process.env.TWILIO_WHATSAPP_FROM;
  const contentSid = process.env.TWILIO_CONTENT_SID;

  if (!sid || !token || !from || !contentSid) {
    console.error('[OTP twilio_wa] Twilio credentials missing in env');
    return { ok: false, message: 'Twilio credentials not configured' };
  }

  const url = `https://api.twilio.com/2010-04-01/Accounts/${sid}/Messages.json`;
  const auth = Buffer.from(`${sid}:${token}`).toString('base64');

  const bodyParams = new URLSearchParams();
  bodyParams.append('From', from);
  bodyParams.append('To', `whatsapp:${phone}`);
  bodyParams.append('ContentSid', contentSid);
  bodyParams.append('ContentVariables', JSON.stringify({ '1': code, '2': verifyUrl }));

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': `Basic ${auth}`,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: bodyParams,
    });

    const respText = await response.text();
    return {
      ok: response.status < 400,
      message: respText.slice(0, 300),
    };
  } catch (err: any) {
    console.error('[OTP twilio_wa] error:', err);
    return { ok: false, message: err?.message || 'Twilio send failure' };
  }
}

export function generateOtpCode(): string {
  return Math.floor(100000 + Math.random() * 900000).toString();
}

export function normalizePhone(raw: string): string {
  let digits = raw.replace(/\D+/g, '');
  if (digits.length === 10) {
    digits = '91' + digits;
  }
  if (digits.length > 15) {
    digits = digits.slice(-12);
  }
  return '+' + digits;
}
