import React from 'react';

export default function AdminLoading() {
  return (
    <div className="space-y-6">
      {/* Skeleton Top Alert/Banner */}
      <div className="es-skeleton-box animate-pulse" style={{ height: 64 }} />

      {/* Skeleton Funnel/Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        {[...Array(5)].map((_, i) => (
          <div 
            key={i} 
            className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-4 animate-pulse"
            style={{ height: 112, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', animationDelay: `${i * 100}ms` }}
          >
            <div className="es-skeleton-box" style={{ height: 12, width: '60%' }} />
            <div className="es-skeleton-box" style={{ height: 28, width: '40%', marginTop: 8 }} />
            <div className="es-skeleton-box" style={{ height: 10, width: '75%' }} />
          </div>
        ))}
      </div>

      {/* Skeleton Chart & Concerns Breakdown Grid */}
      <div className="grid lg:grid-cols-3 gap-4">
        {/* Chart Skeleton */}
        <div 
          className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5 lg:col-span-2 animate-pulse"
          style={{ height: 256, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div className="es-skeleton-box" style={{ height: 16, width: '30%' }} />
            <div className="es-skeleton-box" style={{ height: 10, width: '50%' }} />
          </div>
          <div className="es-chart-container">
            {[...Array(14)].map((_, i) => (
              <div key={i} className="es-chart-column">
                <div className="es-chart-bar-container">
                  <div 
                    className="es-skeleton-box" 
                    style={{ 
                      width: '100%',
                      height: `${20 + (i % 5) * 16}%`,
                      borderBottomLeftRadius: 0,
                      borderBottomRightRadius: 0
                    }} 
                  />
                </div>
                <div className="es-chart-label" style={{ height: 10, width: 14, backgroundColor: 'transparent' }} />
              </div>
            ))}
          </div>
        </div>

        {/* Concern Breakdown Skeleton */}
        <div 
          className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-5 animate-pulse"
          style={{ height: 256, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', animationDelay: '150ms' }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div className="es-skeleton-box" style={{ height: 16, width: '60%' }} />
            <div className="es-skeleton-box" style={{ height: 10, width: '40%' }} />
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 16 }}>
            {[...Array(3)].map((_, i) => (
              <div key={i} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <div className="es-skeleton-box" style={{ height: 10, width: '50%' }} />
                  <div className="es-skeleton-box" style={{ height: 10, width: '20%' }} />
                </div>
                <div className="es-skeleton-box" style={{ height: 8, width: '100%', borderRadius: 9999 }} />
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Floating Modern Overlay Navigation Loader */}
      <div className="es-nav-overlay animate-scale-in">
        <div className="es-spinner" />
        <span className="es-nav-overlay-text">Navigating Console...</span>
      </div>
    </div>
  );
}
