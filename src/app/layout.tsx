import type { Metadata } from 'next';
import './globals.css';
import { I18nProvider } from '@/components/I18nContext';
import AppChrome from '@/components/AppChrome';

export const metadata: Metadata = {
  title: 'Empower Students — Child Assessment Platform',
  description: 'A multi-disciplinary developmental assessment platform for children, matching AI screening with clinical feedback.',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <head>
        <link rel="icon" type="image/png" href="/logo-small.png" />
      </head>
      <body>
        <I18nProvider>
          <AppChrome>{children}</AppChrome>
        </I18nProvider>
      </body>
    </html>
  );
}
