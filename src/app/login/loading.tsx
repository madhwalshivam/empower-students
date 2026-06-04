import React from 'react';

// Shown automatically (via Suspense) while the login page's server-side auth
// check runs. Without this the main area sat completely blank until that network
// call resolved — the ugly flash the user saw. A card-shaped skeleton makes the
// wait feel intentional and instant.
export default function LoginLoading() {
  return (
    <div className="w-full flex flex-col items-center justify-center px-4" style={{ minHeight: '60vh' }}>
      <div className="text-center mb-8 flex flex-col items-center">
        <div className="es-skeleton-box" style={{ height: 36, width: 220, borderRadius: 12 }} />
        <div className="es-skeleton-box" style={{ height: 14, width: 300, maxWidth: '80vw', marginTop: 14, borderRadius: 8 }} />
      </div>

      <div className="card-premium max-w-md w-full mx-auto border-2 border-indigo-100 bg-white dark:bg-slate-900 shadow-xl rounded-3xl p-6 sm:p-8">
        <div className="flex items-center justify-center mb-6">
          <div className="es-skeleton-box" style={{ width: 48, height: 48, borderRadius: 9999 }} />
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          {[0, 1].map((i) => (
            <div key={i} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div className="es-skeleton-box" style={{ height: 11, width: 110, borderRadius: 6 }} />
              <div className="es-skeleton-box" style={{ height: 46, width: '100%', borderRadius: 12 }} />
            </div>
          ))}
          <div className="es-skeleton-box" style={{ height: 48, width: '100%', borderRadius: 12, marginTop: 6 }} />
        </div>
      </div>
    </div>
  );
}
