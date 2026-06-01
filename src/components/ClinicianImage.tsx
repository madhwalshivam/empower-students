'use client';

import React, { useState } from 'react';

interface ClinicianImageProps {
  src: string;
  alt: string;
  className: string;
  initial: string;
  fallbackClassName?: string;
}

export default function ClinicianImage({ 
  src, 
  alt, 
  className, 
  initial, 
  fallbackClassName 
}: ClinicianImageProps) {
  const [hasError, setHasError] = useState(false);

  if (hasError || !src) {
    return (
      <div className={fallbackClassName || "w-20 h-20 rounded-full bg-indigo-50 text-indigo-600 font-bold text-2xl flex items-center justify-center mx-auto mb-3 shadow-inner"}>
        {initial}
      </div>
    );
  }

  return (
    <img
      src={src}
      alt={alt}
      className={className}
      onError={() => setHasError(true)}
    />
  );
}
