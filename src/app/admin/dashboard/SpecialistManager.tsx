'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { 
  addSpecialistAction, 
  toggleSpecialistAction, 
  deleteSpecialistAction 
} from '@/app/actions/admin';
import { 
  Plus, 
  Trash2, 
  Check, 
  AlertTriangle, 
  Power, 
  Stethoscope, 
  X,
  User,
  Sparkles,
  Link as LinkIcon
} from 'lucide-react';

interface Specialist {
  id: number;
  name: string;
  role: string;
  qualifications: string | null;
  bio: string | null;
  photo: string | null;
  order_no: number;
  active: boolean;
}

interface SpecialistManagerProps {
  initialSpecialists: Specialist[];
}

export default function SpecialistManager({ initialSpecialists }: SpecialistManagerProps) {
  const router = useRouter();
  const [showAddForm, setShowAddForm] = useState(false);
  
  // Form fields
  const [name, setName] = useState('');
  const [role, setRole] = useState('');
  const [qualifications, setQualifications] = useState('');
  const [bio, setBio] = useState('');
  const [photo, setPhoto] = useState('');
  const [orderNo, setOrderNo] = useState(100);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const handleAddSpecialist = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess('');
    setLoading(true);

    try {
      const res = await addSpecialistAction({
        name,
        role,
        qualifications,
        bio,
        photo,
        orderNo: Number(orderNo) || 100
      });

      if (res.ok) {
        setSuccess('Doctor successfully added to the panel!');
        setName('');
        setRole('');
        setQualifications('');
        setBio('');
        setPhoto('');
        setOrderNo(100);
        setShowAddForm(false);
        router.refresh();
      } else {
        setError(res.error || 'Failed to add doctor.');
      }
    } catch (err: any) {
      setError(err.message || 'An unexpected error occurred.');
    } finally {
      setLoading(false);
    }
  };

  const handleToggle = async (id: number, currentStatus: boolean) => {
    try {
      const res = await toggleSpecialistAction(id, !currentStatus);
      if (res.ok) {
        router.refresh();
      } else {
        alert(res.error || 'Failed to update status.');
      }
    } catch (err: any) {
      alert(err.message || 'An error occurred.');
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this specialist?')) return;
    try {
      const res = await deleteSpecialistAction(id);
      if (res.ok) {
        router.refresh();
      } else {
        alert(res.error || 'Failed to delete specialist.');
      }
    } catch (err: any) {
      alert(err.message || 'An error occurred.');
    }
  };

  return (
    <div className="space-y-6">
      {/* Section Title & Header */}
      <div className="flex justify-between items-center flex-wrap gap-4">
        <div>
          <h2 className="text-xl font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
            <Stethoscope className="text-indigo-650" size={22} />
            <span>Manage Doctors & Specialists Panel</span>
          </h2>
          <p className="text-xs text-slate-400 mt-1">
            Add or edit clinicians appearing on the Home Page and "Our Panel" page.
          </p>
        </div>
        
        <button
          onClick={() => setShowAddForm(!showAddForm)}
          className="btn-premium btn-premium-primary text-white font-bold py-2.5 px-4 rounded-xl text-xs flex items-center gap-1.5 cursor-pointer border-0 shadow-sm"
          style={{ outline: 'none' }}
        >
          {showAddForm ? <X size={14} /> : <Plus size={14} />}
          {showAddForm ? 'Cancel' : 'Add New Doctor'}
        </button>
      </div>

      {/* Add Specialist Form */}
      {showAddForm && (
        <form onSubmit={handleAddSpecialist} className="bg-slate-50 dark:bg-slate-950/20 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 space-y-4 animate-slide-down">
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">
            Enter Clinician Details
          </h3>

          {error && (
            <div className="bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-xl p-3 flex items-center gap-2">
              <AlertTriangle size={14} />
              <span>{error}</span>
            </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-bold text-slate-550 dark:text-slate-400 uppercase tracking-wider mb-1">
                Full Name *
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Dr. Pankaj Jha"
                className="input-premium py-2 px-3 text-sm"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-555 dark:text-slate-400 uppercase tracking-wider mb-1">
                Specialty / Role *
              </label>
              <input
                type="text"
                required
                value={role}
                onChange={(e) => setRole(e.target.value)}
                placeholder="e.g. Paediatric Neurologist"
                className="input-premium py-2 px-3 text-sm"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-555 dark:text-slate-400 uppercase tracking-wider mb-1">
                Qualifications
              </label>
              <input
                type="text"
                value={qualifications}
                onChange={(e) => setQualifications(e.target.value)}
                placeholder="e.g. MD Paediatrics, DM Neurology"
                className="input-premium py-2 px-3 text-sm"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-555 dark:text-slate-400 uppercase tracking-wider mb-1">
                Sort Order (Lower appears first)
              </label>
              <input
                type="number"
                value={orderNo}
                onChange={(e) => setOrderNo(Number(e.target.value))}
                placeholder="100"
                className="input-premium py-2 px-3 text-sm"
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-xs font-bold text-slate-555 dark:text-slate-400 uppercase tracking-wider mb-1">
                Photo URL / Asset File Name
              </label>
              <div className="flex gap-2">
                <input
                  type="text"
                  value={photo}
                  onChange={(e) => setPhoto(e.target.value)}
                  placeholder="e.g. https://example.com/dr-jha.jpg or just image filename"
                  className="flex-1 input-premium py-2 px-3 text-sm"
                />
              </div>
              <p className="text-[10px] text-slate-400 mt-1">
                Tip: You can paste a public link of the doctor's photo, or just specify an image file.
              </p>
            </div>

            <div className="md:col-span-2">
              <label className="block text-xs font-bold text-slate-555 dark:text-slate-400 uppercase tracking-wider mb-1">
                Bio / Detailed Profile Description
              </label>
              <textarea
                value={bio}
                onChange={(e) => setBio(e.target.value)}
                placeholder="e.g. Experienced child development consultant specializing in learning and behavior disorders."
                rows={3}
                className="input-premium py-2 px-3 text-sm w-full font-sans"
                style={{ resize: 'vertical' }}
              />
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={() => setShowAddForm(false)}
              className="text-xs text-slate-500 font-bold px-4 py-2 bg-transparent border-0 cursor-pointer"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="btn-premium btn-premium-primary text-white font-bold py-2 px-6 rounded-xl text-xs cursor-pointer border-0 shadow-sm"
            >
              {loading ? 'Adding...' : 'Save Clinician'}
            </button>
          </div>
        </form>
      )}

      {/* Success alert */}
      {success && (
        <div className="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs rounded-xl p-3 flex items-center gap-2">
          <Check size={14} />
          <span>{success}</span>
        </div>
      )}

      {/* Specialists List */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {initialSpecialists.length === 0 ? (
          <p className="text-sm text-slate-400 italic py-6 col-span-full text-center">
            No specialists added yet. Click "Add New Doctor" above to get started.
          </p>
        ) : (
          initialSpecialists.map((sp) => {
            const initial = sp.name ? sp.name.substring(0, 1).toUpperCase() : '?';
            const isExternal = sp.photo && (sp.photo.startsWith('http') || sp.photo.startsWith('/') || sp.photo.startsWith('data:'));
            const photoUrl = isExternal ? sp.photo! : `/assets/images/${sp.photo}`;

            return (
              <div 
                key={sp.id} 
                className={`bg-white dark:bg-slate-900 border rounded-3xl p-5 flex flex-col justify-between transition-all ${
                  sp.active 
                    ? 'border-slate-100 dark:border-slate-800/80 shadow-sm' 
                    : 'border-slate-200 border-dashed opacity-60 bg-slate-50/50'
                }`}
              >
                <div>
                  <div className="flex items-center gap-3.5 mb-4">
                    {/* Photo */}
                    <div className="w-12 h-12 rounded-full overflow-hidden bg-slate-50 dark:bg-slate-800 border-2 border-indigo-50/60 flex items-center justify-center flex-shrink-0">
                      {sp.photo ? (
                        <img 
                          src={photoUrl} 
                          alt={sp.name} 
                          className="w-full h-full object-cover"
                          onError={(e) => {
                            (e.target as HTMLElement).outerHTML = `<div class="text-indigo-650 font-bold text-lg">${initial}</div>`;
                          }}
                        />
                      ) : (
                        <div className="text-indigo-650 font-bold text-lg">{initial}</div>
                      )}
                    </div>
                    <div>
                      <h4 className="font-bold text-sm text-slate-800 dark:text-slate-100 leading-tight">
                        {sp.name}
                      </h4>
                      <p className="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 uppercase mt-0.5">
                        {sp.role}
                      </p>
                    </div>
                  </div>

                  <div className="space-y-1.5 text-xs text-slate-500 dark:text-slate-400">
                    {sp.qualifications && (
                      <p className="font-medium text-slate-700 dark:text-slate-300">
                        🎓 {sp.qualifications}
                      </p>
                    )}
                    {sp.bio && (
                      <p className="line-clamp-2 leading-relaxed">
                        {sp.bio}
                      </p>
                    )}
                    <p className="text-[10px] text-slate-400">
                      Sort order: {sp.order_no}
                    </p>
                  </div>
                </div>

                <div className="flex items-center justify-between border-t border-slate-100 dark:border-slate-800/60 pt-3.5 mt-4">
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full uppercase ${
                    sp.active 
                      ? 'bg-emerald-50 text-emerald-700' 
                      : 'bg-slate-100 text-slate-500'
                  }`}>
                    {sp.active ? 'Active' : 'Inactive'}
                  </span>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleToggle(sp.id, sp.active)}
                      className={`w-7 h-7 rounded-lg flex items-center justify-center cursor-pointer border-0 transition-all ${
                        sp.active 
                          ? 'bg-amber-50 hover:bg-amber-100 text-amber-700' 
                          : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700'
                      }`}
                      title={sp.active ? 'Deactivate' : 'Activate'}
                      style={{ outline: 'none' }}
                    >
                      <Power size={13} />
                    </button>
                    <button
                      onClick={() => handleDelete(sp.id)}
                      className="w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 flex items-center justify-center cursor-pointer border-0 transition-all"
                      title="Delete Doctor"
                      style={{ outline: 'none' }}
                    >
                      <Trash2 size={13} />
                    </button>
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
