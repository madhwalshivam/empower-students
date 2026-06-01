'use client';

import React, { createContext, useContext, useState, useEffect } from 'react';

const translations: Record<string, Record<string, string>> = {
  en: {
    'brand': 'Empower Students',
    'nav.home': 'Home',
    'nav.panel': 'Our Panel',
    'nav.about': 'About',
    'nav.login': 'Login',
    'nav.dashboard': 'Dashboard',
    'nav.logout': 'Logout',
    'nav.wallet': 'Wallet',
    'nav.cr': 'cr',
  },
  hi: {
    'brand': 'एम्पावर स्टूडेंट्स',
    'nav.home': 'होम',
    'nav.panel': 'हमारा पैनल',
    'nav.about': 'हमारे बारे में',
    'nav.login': 'लॉगिन',
    'nav.dashboard': 'डैशबोर्ड',
    'nav.logout': 'लॉगआउट',
    'nav.wallet': 'वॉलेट',
    'nav.cr': 'क्रेडिट',

    'hero.eyebrow': '🤖 AI + Clinicians · Parent-First',
    'hero.title.1': 'अपने बच्चे को समझें —',
    'hero.title.2': 'हर पहलू में',
    'hero.subtitle': 'आपके बच्चे की सेहत, मन, भावनाएँ, व्यवहार, भाषा, गणित, बोलने की क्षमता और विशेष प्रतिभा का एक मित्रवत 360° मूल्यांकन।',
    'cta.start': 'Start free →',
    'cta.meet': 'पैनल से मिलें',

    'child.back': '← सभी बच्चे',
    'child.modules': 'मूल्यांकन मॉड्यूल',
    'child.report': 'पूरी AI रिपोर्ट',
    'child.start': 'Start',
    'child.redo': 'Re-do',
    'child.years': 'वर्ष',
    'child.summary': 'Done',
  }
};

type Language = 'en' | 'hi';

interface I18nContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
  t: (key: string) => string;
}

const I18nContext = createContext<I18nContextType | undefined>(undefined);

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [language, setLanguageState] = useState<Language>('en');

  useEffect(() => {
    try {
      const saved = localStorage.getItem('es_lang') as Language;
      if (saved === 'en' || saved === 'hi') {
        setLanguageState(saved);
      }
    } catch {
      // ignore
    }
  }, []);

  const setLanguage = (lang: Language) => {
    setLanguageState(lang);
    try {
      localStorage.setItem('es_lang', lang);
    } catch {
      // ignore
    }
  };

  const t = (key: string): string => {
    return translations[language]?.[key] || translations['en']?.[key] || key;
  };

  return (
    <I18nContext.Provider value={{ language, setLanguage, t }}>
      {children}
    </I18nContext.Provider>
  );
}

export function useTranslation() {
  const context = useContext(I18nContext);
  if (!context) {
    throw new Error('useTranslation must be used within an I18nProvider');
  }
  return context;
}
