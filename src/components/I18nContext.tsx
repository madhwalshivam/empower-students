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

    // Evaluation screen
    'eval.ready': 'Ready to Start?',
    'eval.aboutA': 'You are about to start the',
    'eval.aboutB': 'evaluation for',
    'eval.cost': 'This module costs',
    'eval.credits': 'credits',
    'eval.free': 'Free',
    'eval.balance': 'Your balance',
    'eval.willDeductA': 'credits will be deducted when you tap Start. New balance:',
    'eval.needMoreA': 'You need',
    'eval.needMoreB': 'more credits to start this module.',
    'eval.instructions': 'Instructions:',
    'eval.inst1': 'Ensure the room is quiet for voice prompts.',
    'eval.inst2': 'Read or listen to the task together.',
    'eval.inst3': 'Type or use the Microphone button to dictate answers.',
    'eval.inst4': 'The evaluation will adapt based on correct responses.',
    'eval.cancel': 'Cancel',
    'eval.start': 'Start Evaluation',
    'eval.startShort': 'Start',
    'eval.resume': 'Resume Evaluation',
    'eval.resumeNote': 'You already paid for this module — resume for free, continuing from where you left off.',
    'eval.topup': 'Top Up to Start',
    'eval.loading': 'Loading next activity. Please wait...',
    'eval.memorise': 'Memorise This Stimulus',
    'eval.hiding': 'Hiding stimulus in:',
    'eval.evaluation': "'s Evaluation",
    'eval.question': 'Question',
    'eval.of': 'of',
    'eval.typeAnswer': 'Type the answer here, or click the mic to speak...',
    'eval.speak': 'Click to Speak',
    'eval.stop': 'Stop Listening',
    'eval.submit': 'Submit & Continue',
    'eval.completed': 'Evaluation Completed',
    'eval.overall': 'Overall Score',
    'eval.level': 'Level',
    'eval.aiSummary': 'AI Summary & Insights',
    'eval.focusRec': 'Focus Recommendation',
    'eval.strengths': 'Key Strengths',
    'eval.done': 'Done & Return to Dashboard',
    'eval.errTitle': 'Something Went Wrong',
    'eval.notEnough': 'Not Enough Credits',
    'eval.tryAgain': 'Try Again',
    'eval.goBack': 'Go Back',
    'eval.topupWallet': 'Top Up Wallet',
    'eval.pickOption': 'Please pick an option first.',
    'eval.enterAnswer': 'Please enter or record an answer first.',
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

    // Evaluation screen
    'eval.ready': 'शुरू करने के लिए तैयार?',
    'eval.aboutA': 'आप शुरू करने वाले हैं',
    'eval.aboutB': 'मूल्यांकन — इस बच्चे के लिए:',
    'eval.cost': 'इस मॉड्यूल की कीमत',
    'eval.credits': 'क्रेडिट',
    'eval.free': 'मुफ़्त',
    'eval.balance': 'आपका बैलेंस',
    'eval.willDeductA': 'क्रेडिट Start दबाते ही कट जाएँगे। नया बैलेंस:',
    'eval.needMoreA': 'आपको',
    'eval.needMoreB': 'और क्रेडिट चाहिए इस मॉड्यूल को शुरू करने के लिए।',
    'eval.instructions': 'निर्देश:',
    'eval.inst1': 'आवाज़ वाले सवालों के लिए कमरा शांत रखें।',
    'eval.inst2': 'सवाल को साथ में पढ़ें या सुनें।',
    'eval.inst3': 'जवाब टाइप करें या माइक बटन से बोलें।',
    'eval.inst4': 'सही जवाबों के अनुसार मूल्यांकन कठिन/आसान होता रहेगा।',
    'eval.cancel': 'रद्द करें',
    'eval.start': 'मूल्यांकन शुरू करें',
    'eval.startShort': 'शुरू करें',
    'eval.resume': 'मूल्यांकन फिर से शुरू करें',
    'eval.resumeNote': 'इस मॉड्यूल का भुगतान हो चुका है — मुफ़्त में वहीं से जारी रखें जहाँ छोड़ा था।',
    'eval.topup': 'शुरू करने के लिए टॉप-अप करें',
    'eval.loading': 'अगली गतिविधि लोड हो रही है। कृपया प्रतीक्षा करें...',
    'eval.memorise': 'इसे याद रखें',
    'eval.hiding': 'छिपने में समय:',
    'eval.evaluation': ' का मूल्यांकन',
    'eval.question': 'प्रश्न',
    'eval.of': 'में से',
    'eval.typeAnswer': 'यहाँ जवाब टाइप करें, या बोलने के लिए माइक दबाएँ...',
    'eval.speak': 'बोलने के लिए दबाएँ',
    'eval.stop': 'सुनना बंद करें',
    'eval.submit': 'जमा करें और आगे बढ़ें',
    'eval.completed': 'मूल्यांकन पूरा हुआ',
    'eval.overall': 'कुल स्कोर',
    'eval.level': 'स्तर',
    'eval.aiSummary': 'AI सारांश और जानकारी',
    'eval.focusRec': 'फ़ोकस सुझाव',
    'eval.strengths': 'मुख्य ख़ूबियाँ',
    'eval.done': 'पूरा करें और डैशबोर्ड पर लौटें',
    'eval.errTitle': 'कुछ गड़बड़ हो गई',
    'eval.notEnough': 'पर्याप्त क्रेडिट नहीं',
    'eval.tryAgain': 'फिर से कोशिश करें',
    'eval.goBack': 'वापस जाएँ',
    'eval.topupWallet': 'वॉलेट टॉप-अप करें',
    'eval.pickOption': 'कृपया पहले एक विकल्प चुनें।',
    'eval.enterAnswer': 'कृपया पहले जवाब लिखें या रिकॉर्ड करें।',
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
