'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { signUpAction } from '@/app/actions/email-auth';
import { AlertTriangle, Check, User, Mail, Phone, Lock, Eye, EyeOff, Tag, MapPin, CreditCard } from 'lucide-react';

export default function SignUpForm() {
  const router = useRouter();
  const [role, setRole] = useState<'parent' | 'partner'>('parent');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  
  // Partner fields
  const [referralCode, setReferralCode] = useState('');
  const [city, setCity] = useState('');
  const [upiId, setUpiId] = useState('');

  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess('');

    if (password !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }

    setLoading(true);

    try {
      const res = await signUpAction(
        name,
        email,
        phone,
        password,
        role,
        role === 'partner' ? { referralCode, city, upiId } : undefined
      );

      if (res.ok) {
        if (role === 'partner') {
          setSuccess('Registration request sent successfully! You can log in once the admin approves your account. Redirecting to login page...');
        } else {
          setSuccess('Account created successfully! Redirecting to login page...');
        }
        setTimeout(() => {
          router.push('/login');
          router.refresh();
        }, 4000);
      } else {
        setError(res.error || 'Sign up failed. Please try again.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card-premium max-w-md w-full mx-auto border-2 border-indigo-100 bg-white dark:bg-slate-900 shadow-xl rounded-3xl p-6 sm:p-8 animate-fade-in">
      {/* Role Toggle Tabs */}
      <div 
        style={{ 
          display: 'flex', 
          width: '100%', 
          backgroundColor: 'var(--background)',
          border: '1.5px solid var(--surface-border)',
          padding: '6px', 
          borderRadius: '16px',
          marginBottom: '24px'
        }}
      >
        <button
          type="button"
          onClick={() => {
            setRole('parent');
            setError('');
          }}
          style={{
            flex: 1,
            padding: '10px',
            borderRadius: '12px',
            cursor: 'pointer',
            fontWeight: 'bold',
            fontSize: '14px',
            border: 'none',
            outline: 'none',
            backgroundColor: role === 'parent' ? 'var(--surface)' : 'transparent',
            color: role === 'parent' ? 'var(--primary)' : 'var(--text-secondary)',
            boxShadow: role === 'parent' ? 'var(--shadow-sm)' : 'none',
            transition: 'all 0.2s ease-in-out'
          }}
        >
          Parent Account
        </button>
        <button
          type="button"
          onClick={() => {
            setRole('partner');
            setError('');
          }}
          style={{
            flex: 1,
            padding: '10px',
            borderRadius: '12px',
            cursor: 'pointer',
            fontWeight: 'bold',
            fontSize: '14px',
            border: 'none',
            outline: 'none',
            backgroundColor: role === 'partner' ? 'var(--surface)' : 'transparent',
            color: role === 'partner' ? 'var(--primary)' : 'var(--text-secondary)',
            boxShadow: role === 'partner' ? 'var(--shadow-sm)' : 'none',
            transition: 'all 0.2s ease-in-out'
          }}
        >
          Partner Account
        </button>
      </div>

      {error && (
        <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900 text-rose-800 dark:text-rose-400 text-sm rounded-xl p-3 mb-4 flex items-center gap-2">
          <AlertTriangle size={16} />
          <span>{error}</span>
        </div>
      )}

      {success && (
        <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 text-emerald-800 dark:text-emerald-400 text-sm rounded-xl p-3 mb-4 flex items-center gap-2">
          <Check size={16} />
          <span>{success}</span>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Name Field */}
        <div>
          <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
            {role === 'partner' ? 'Partner / Organization Name' : 'Full Name'}
          </label>
          <div className="relative">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              <User size={16} />
            </div>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={role === 'partner' ? 'e.g. Sunrise Therapy Centre' : 'e.g. Jyoti Sharma'}
              required
              maxLength={80}
              className="input-premium"
              style={{ paddingLeft: '36px' }}
            />
          </div>
        </div>

        {/* Email Field */}
        <div>
          <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
            Email Address
          </label>
          <div className="relative">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              <Mail size={16} />
            </div>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              required
              className="input-premium"
              style={{ paddingLeft: '36px' }}
            />
          </div>
        </div>

        {/* Phone Field */}
        <div>
          <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
            Phone / WhatsApp Number
          </label>
          <div className="relative">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              <Phone size={16} />
            </div>
            <input
              type="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="e.g. 9812345678"
              required
              className="input-premium"
              style={{ paddingLeft: '36px' }}
            />
          </div>
        </div>

        {/* Partner Specific Fields */}
        {role === 'partner' && (
          <>
            {/* Custom Referral Code */}
            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                Your Desired Referral Code
              </label>
              <div className="relative">
                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <Tag size={16} />
                </div>
                <input
                  type="text"
                  value={referralCode}
                  onChange={(e) => setReferralCode(e.target.value)}
                  placeholder="e.g. SUNRISE"
                  required
                  className="input-premium font-mono uppercase"
                  style={{ paddingLeft: '36px' }}
                />
              </div>
              <p className="text-[10px] text-slate-400 mt-1">
                This code will be used in referral links, e.g., empowerstudents.in/r/SUNRISE
              </p>
            </div>

            {/* City */}
            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                City / Location
              </label>
              <div className="relative">
                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <MapPin size={16} />
                </div>
                <input
                  type="text"
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  placeholder="e.g. New Delhi"
                  required
                  className="input-premium"
                  style={{ paddingLeft: '36px' }}
                />
              </div>
            </div>

            {/* UPI ID */}
            <div>
              <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
                UPI ID (For Payouts)
              </label>
              <div className="relative">
                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                  <CreditCard size={16} />
                </div>
                <input
                  type="text"
                  value={upiId}
                  onChange={(e) => setUpiId(e.target.value)}
                  placeholder="e.g. yourname@okaxis"
                  required
                  className="input-premium"
                  style={{ paddingLeft: '36px' }}
                />
              </div>
            </div>
          </>
        )}

        {/* Password Field */}
        <div>
          <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
            Password
          </label>
          <div className="relative">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              <Lock size={16} />
            </div>
            <input
              type={showPassword ? 'text' : 'password'}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Min. 6 characters"
              required
              minLength={6}
              className="input-premium"
              style={{ paddingLeft: '36px', paddingRight: '40px' }}
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 bg-transparent border-none cursor-pointer p-0 outline-none"
              style={{ border: 'none', outline: 'none' }}
            >
              {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          </div>
        </div>

        {/* Confirm Password Field */}
        <div>
          <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">
            Confirm Password
          </label>
          <div className="relative">
            <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
              <Lock size={16} />
            </div>
            <input
              type={showPassword ? 'text' : 'password'}
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              placeholder="Re-enter password"
              required
              minLength={6}
              className="input-premium"
              style={{ paddingLeft: '36px', paddingRight: '40px' }}
            />
          </div>
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full btn-premium btn-premium-primary py-3 text-white font-bold rounded-xl shadow-lg transition-all disabled:opacity-50"
        >
          {loading ? 'Creating Account...' : 'Sign Up'}
        </button>
      </form>

      <div className="mt-6 text-center space-y-3">
        <div className="relative">
          <div className="absolute inset-0 flex items-center">
            <div className="w-full border-t border-slate-200 dark:border-slate-700"></div>
          </div>
          <div className="relative flex justify-center text-xs">
            <span className="bg-white dark:bg-slate-900 px-3 text-slate-400">or</span>
          </div>
        </div>

        <p className="text-sm text-slate-500">
          Already have an account?{' '}
          <Link href="/login" className="text-indigo-600 dark:text-indigo-400 font-bold hover:underline">
            Login here
          </Link>
        </p>
      </div>

      <p className="text-xs text-slate-500 mt-4 text-center">
        By signing up, you agree to our{' '}
        <Link href="/terms" className="underline text-indigo-500 hover:text-indigo-750">
          Terms
        </Link>{' '}
        &amp;{' '}
        <Link href="/privacy" className="underline text-indigo-500 hover:text-indigo-750">
          Privacy Policy
        </Link>
        .
      </p>
    </div>
  );
}
