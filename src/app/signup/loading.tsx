import React from 'react';

// Suspense fallback for the signup page's server-side auth check — replaces the
// blank flash with a form-shaped skeleton. See login/loading.tsx for the why.
export default function SignUpLoading() {
  return (
    <div className="w-full flex flex-col items-center justify-center px-4" style={{ minHeight: '60vh' }}>
      <div className="text-center mb-8 flex flex-col items-center">
        <div className="es-skeleton-box" style={{ height: 36, width: 280, maxWidth: '85vw', borderRadius: 12 }} />
        <div className="es-skeleton-box" style={{ height: 14, width: 320, maxWidth: '80vw', marginTop: 14, borderRadius: 8 }} />
      </div>

      <div className="card-premium max-w-md w-full mx-auto border-2 border-indigo-100 bg-white dark:bg-slate-900 shadow-xl rounded-3xl p-6 sm:p-8">
        {/* role toggle */}
        <div className="es-skeleton-box" style={{ height: 48, width: '100%', borderRadius: 16, marginBottom: 24 }} />

        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          {[0, 1, 2, 3].map((i) => (
            <div key={i} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div className="es-skeleton-box" style={{ height: 11, width: 120, borderRadius: 6 }} />
              <div className="es-skeleton-box" style={{ height: 46, width: '100%', borderRadius: 12 }} />
            </div>
          ))}
          <div className="es-skeleton-box" style={{ height: 48, width: '100%', borderRadius: 12, marginTop: 6 }} />
        </div>
      </div>
    </div>
  );
}
