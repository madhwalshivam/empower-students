'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { addChildAction } from '@/app/actions/child';

export default function AddChildPage() {
  const router = useRouter();
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const [formData, setFormData] = useState({
    name: '',
    dob: '',
    gender: '',
    school: '',
    class_grade: '',
    mother_tongue: '',
    languages: '',
    diagnosis: '',
    notes: '',
  });

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value,
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const res = await addChildAction(formData);
      if (res.ok && res.childId) {
        router.push(`/child/${res.childId}`);
        router.refresh();
      } else {
        setError(res.error || 'Failed to register child profile.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="max-w-2xl mx-auto card-premium bg-white dark:bg-slate-900 border border-slate-100 p-6 sm:p-8 animate-fade-in">
      <h1 className="heading-fun text-3xl font-bold mb-1 text-slate-800 dark:text-slate-100">
        Add a Child
      </h1>
      <p className="text-sm text-slate-500 mb-6">
        Just the basics. You can add more details later.
      </p>

      {error && (
        <div className="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-xl p-3.5 mb-4">
          ⚠️ {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
            Child's Name *
          </label>
          <input
            name="name"
            type="text"
            required
            maxLength={80}
            value={formData.name}
            onChange={handleChange}
            placeholder="e.g. Aarav Sharma"
            className="input-premium"
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              Date of Birth *
            </label>
            <input
              name="dob"
              type="date"
              required
              value={formData.dob}
              onChange={handleChange}
              className="input-premium"
            />
          </div>
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              Gender
            </label>
            <select
              name="gender"
              value={formData.gender}
              onChange={handleChange}
              className="input-premium"
            >
              <option value="">— Select Gender —</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              School
            </label>
            <input
              name="school"
              type="text"
              maxLength={120}
              value={formData.school}
              onChange={handleChange}
              placeholder="e.g. Lotus Valley International"
              className="input-premium"
            />
          </div>
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              Class / Grade
            </label>
            <input
              name="class_grade"
              type="text"
              maxLength={40}
              value={formData.class_grade}
              onChange={handleChange}
              placeholder="e.g. KG, Class 3"
              className="input-premium"
            />
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              Mother Tongue
            </label>
            <input
              name="mother_tongue"
              type="text"
              maxLength={40}
              value={formData.mother_tongue}
              onChange={handleChange}
              placeholder="e.g. Hindi, Bengali, Tamil"
              className="input-premium"
            />
          </div>
          <div>
            <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
              Languages Spoken
            </label>
            <input
              name="languages"
              type="text"
              maxLength={80}
              value={formData.languages}
              onChange={handleChange}
              placeholder="e.g. Hindi, English"
              className="input-premium"
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
            Known Diagnosis (if any)
          </label>
          <input
            name="diagnosis"
            type="text"
            maxLength={200}
            value={formData.diagnosis}
            onChange={handleChange}
            placeholder="e.g. ASD, ADHD, GDD, none"
            className="input-premium"
          />
        </div>

        <div>
          <label className="block text-sm font-bold text-slate-600 dark:text-slate-400 mb-1">
            Anything else we should know?
          </label>
          <textarea
            name="notes"
            rows={3}
            value={formData.notes}
            onChange={handleChange}
            placeholder="Tell us a bit about their interests, behavior, or any developmental milestones..."
            className="input-premium"
          ></textarea>
        </div>

        <div className="flex gap-4 pt-4">
          <Link
            href="/dashboard"
            className="flex-1 text-center border-2 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 py-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 font-bold transition-all text-sm"
          >
            Cancel
          </Link>
          <button
            type="submit"
            disabled={loading}
            className="flex-1 brand-grad text-white py-3 rounded-xl font-bold hover:shadow-lg disabled:opacity-50 text-sm"
          >
            {loading ? 'Saving...' : 'Save & Continue'}
          </button>
        </div>
      </form>
    </div>
  );
}
