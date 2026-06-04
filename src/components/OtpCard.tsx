'use client';

import React, { useState } from 'react';
import { sendOtpAction, verifyOtpAction } from '@/app/actions/auth';
import { AlertTriangle, Check, ArrowRight, ArrowLeft } from 'lucide-react';

export default function OtpCard() {
  const [step, setStep] = useState<'phone' | 'otp'>('phone');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');
  const [demoOtp, setDemoOtp] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSendOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setInfo('');
    setLoading(true);

    try {
      const res = await sendOtpAction(name, phone);
      if (res.ok) {
        setStep('otp');
        setInfo('OTP sent on WhatsApp. Valid for 5 minutes.');
        if (res.code) {
          setDemoOtp(res.code);
        }
      } else {
        setError(res.error || 'Failed to send OTP. Please try again.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const res = await verifyOtpAction(phone, name, otp);
      if (res.ok) {
        // Full-page navigation so the new session cookies are picked up — a
        // client router.push can land on a cached logged-out dashboard.
        window.location.assign('/dashboard');
        return;
      } else {
        setError(res.error || 'Invalid OTP. Please try again.');
      }
    } catch (err: any) {
      setError(err.message || 'Verification failed.');
    }
    setLoading(false);
  };

  return (
    <div className="card-premium max-w-md w-full mx-auto border-2 border-indigo-100 bg-white dark:bg-slate-900 shadow-xl rounded-3xl p-6 sm:p-8">
      {step === 'phone' ? (
        <>
          <h3 className="heading-fun text-2xl font-bold mb-1 text-slate-800 dark:text-slate-100 flex items-center justify-between">
            <span>Start free</span>
            <ArrowRight size={20} className="text-indigo-650" />
          </h3>
          <p className="text-sm text-slate-500 mb-6">
            Two simple steps. OTP on WhatsApp.
          </p>

          {error && (
            <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900 text-rose-800 dark:text-rose-400 text-sm rounded-xl p-3 mb-4 flex items-center gap-2">
              <AlertTriangle size={16} />
              <span>{error}</span>
            </div>
          )}

          <form onSubmit={handleSendOtp} className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                Your Name
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Jyoti"
                required
                maxLength={80}
                className="input-premium"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                WhatsApp Number
              </label>
              <input
                type="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="+91 98XXX XXXXX"
                required
                className="input-premium"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full btn-premium btn-premium-primary py-3 text-white font-bold rounded-xl shadow-lg transition-all disabled:opacity-50"
            >
              {loading ? 'Sending OTP...' : 'Send OTP'}
            </button>
          </form>

          <p className="text-xs text-slate-500 mt-4 text-center">
            We never share your data. Read our{' '}
            <a href="/about" className="underline text-indigo-500 hover:text-indigo-750">
              Terms & Privacy
            </a>
            .
          </p>
        </>
      ) : (
        <>
          <h3 className="heading-fun text-2xl font-bold mb-1 text-slate-800 dark:text-slate-100">
            Enter OTP
          </h3>
          <p className="text-sm text-slate-500 mb-6">
            Sent on WhatsApp to <strong className="text-slate-700 dark:text-slate-350">{phone}</strong>
          </p>

          {error && (
            <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900 text-rose-800 dark:text-rose-400 text-sm rounded-xl p-3 mb-4 flex items-center gap-2">
              <AlertTriangle size={16} />
              <span>{error}</span>
            </div>
          )}

          {info && !error && (
            <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 text-emerald-800 dark:text-emerald-400 text-sm rounded-xl p-3 mb-4 flex items-center gap-2">
              <Check size={16} />
              <span>{info}</span>
            </div>
          )}

          {demoOtp && (
            <div className="bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900 text-indigo-800 dark:text-indigo-400 text-sm rounded-xl p-3 mb-4 font-mono text-center">
              DEMO OTP: <strong className="text-lg text-indigo-650">{demoOtp}</strong>
            </div>
          )}

          <form onSubmit={handleVerifyOtp} className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                6-Digit Code
              </label>
              <input
                type="text"
                inputMode="numeric"
                pattern="\d{4,6}"
                maxLength={6}
                value={otp}
                onChange={(e) => setOtp(e.target.value)}
                placeholder="••••••"
                required
                className="w-full text-center text-2xl tracking-[0.4em] font-bold p-3 border-2 border-indigo-100 rounded-xl bg-indigo-50/20 focus:outline-none focus:border-indigo-500 transition-colors"
                autoFocus
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full btn-premium btn-premium-primary py-3 text-white font-bold rounded-xl shadow-lg transition-all disabled:opacity-50"
            >
              {loading ? 'Verifying...' : 'Verify & Continue'}
            </button>
          </form>

          <div className="text-center mt-4">
            <button
              onClick={() => {
                setStep('phone');
                setOtp('');
                setError('');
                setInfo('');
              }}
              className="text-xs text-slate-505 hover:text-indigo-600 underline bg-transparent border-none cursor-pointer flex items-center gap-1 mx-auto"
            >
              <ArrowLeft size={12} /> Use a different number
            </button>
          </div>
        </>
      )}
    </div>
  );
}
