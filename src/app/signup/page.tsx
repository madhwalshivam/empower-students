import React from 'react';
import { redirect } from 'next/navigation';
import { createClient } from '@/lib/supabase/server';
// Import client-side signup form component
import SignUpForm from './SignUpForm';

export default async function SignUpPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();

  if (user) {
    redirect('/dashboard');
  }

  return (
    <div className="w-full flex flex-col items-center justify-center min-h-[60vh] px-4">
      <div className="text-center mb-8">
        <h1 className="heading-fun text-3xl sm:text-4xl font-extrabold text-slate-800 dark:text-slate-100 mb-2">
          Create Your Account
        </h1>
        <p className="text-slate-500 max-w-sm mx-auto">
          Sign up to access child assessments, growth plans, and your parent dashboard.
        </p>
      </div>
      <SignUpForm />
    </div>
  );
}
