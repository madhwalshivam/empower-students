import PageSkeleton from '@/components/PageSkeleton';

// Global fallback: any route that awaits server work and lacks a closer
// loading.tsx shows this instead of a blank screen.
export default function Loading() {
  return <PageSkeleton variant="generic" />;
}
