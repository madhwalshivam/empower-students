// Seed Hindi dictionary for the most common UI strings. These render INSTANTLY
// (no API call, no flicker). Anything not here is auto-translated by Claude at
// runtime and cached. Keys are matched against the trimmed text of a DOM node.

export const HINDI_DICT: Record<string, string> = {
  // Brand / chrome
  'Empower Students': 'एम्पावर स्टूडेंट्स',
  'Parent Portal': 'पैरेंट पोर्टल',
  'Parent Dashboard': 'पैरेंट डैशबोर्ड',
  'Child Profile': 'बच्चे की प्रोफ़ाइल',

  // Sidebar / nav
  'Dashboard': 'डैशबोर्ड',
  'Wallet & Topup': 'वॉलेट और टॉप-अप',
  'Wallet': 'वॉलेट',
  'Our Specialists': 'हमारे विशेषज्ञ',
  'About Us': 'हमारे बारे में',
  'About': 'हमारे बारे में',
  'Home': 'होम',
  'Our Panel': 'हमारा पैनल',
  'View Homepage': 'होमपेज देखें',
  'Logout': 'लॉगआउट',
  'Login': 'लॉगिन',
  'Sign Up': 'साइन अप',

  // Dashboard
  'Add Child': 'बच्चा जोड़ें',
  '+ Add Child': '+ बच्चा जोड़ें',
  'MY CHILDREN': 'मेरे बच्चे',
  'My Children': 'मेरे बच्चे',
  'You have': 'आपके पास',
  'wallet credits': 'वॉलेट क्रेडिट',
  'View Profile & Modules': 'प्रोफ़ाइल और मॉड्यूल देखें',
  'MODULES COMPLETED': 'मॉड्यूल पूरे हुए',
  'Modules Completed': 'मॉड्यूल पूरे हुए',
  'Premium Clinical Assessments': 'प्रीमियम क्लिनिकल मूल्यांकन',
  'Speech & Language': 'बोली और भाषा',
  'Parent Reflection': 'पैरेंट रिफ्लेक्शन',
  'Premium': 'प्रीमियम',
  'Diagnosis: none': 'निदान: कोई नहीं',
  'Diagnosis:': 'निदान:',
  'none': 'कोई नहीं',
  'Male': 'लड़का',
  'Female': 'लड़की',
  'done': 'पूरा',
  'Done': 'पूरा',

  // Child profile / assessment
  'Back to Dashboard': 'डैशबोर्ड पर वापस',
  'Assessment Progress': 'मूल्यांकन प्रगति',
  'View AI Report': 'AI रिपोर्ट देखें',
  'Assessment Modules': 'मूल्यांकन मॉड्यूल',
  'Health Screening': 'स्वास्थ्य जाँच',
  'Mind Power': 'दिमागी क्षमता',
  'Behaviour': 'व्यवहार',
  'Behavior': 'व्यवहार',
  'General Awareness': 'सामान्य ज्ञान',
  'Special Talent': 'विशेष प्रतिभा',
  'Maths Level': 'गणित स्तर',
  'Language & Reading': 'भाषा और पढ़ाई',
  'Diet & Nutrition': 'आहार और पोषण',
  'Begin': 'शुरू करें',
  'Resume': 'जारी रखें',
  'Redo': 'दोबारा करें',
  'Not started': 'शुरू नहीं हुआ',
  'In progress': 'चल रहा है',
  'Unlock Care Pack': 'केयर पैक अनलॉक करें',
  'Growth Plan': 'ग्रोथ प्लान',
  'Personal Course': 'पर्सनल कोर्स',
  'Daily Tracker': 'डेली ट्रैकर',

  // Buttons / generic
  'Cancel': 'रद्द करें',
  'Continue': 'जारी रखें',
  'Submit': 'जमा करें',
  'Save': 'सेव करें',
  'Confirm': 'पुष्टि करें',
  'Top Up Wallet': 'वॉलेट टॉप-अप करें',
  'Top Up': 'टॉप-अप',
  'Start': 'शुरू करें',
  'Back': 'वापस',
  'Yes': 'हाँ',
  'No': 'नहीं',
  'Loading…': 'लोड हो रहा है…',
  'Loading...': 'लोड हो रहा है...',
  'cr': 'क्रेडिट',
  'Hi': 'नमस्ते',
  'Hello': 'नमस्ते',
  'User': 'यूज़र',

  // App-shell page titles (UserShell.getPageTitle)
  'Wallet & Transactions': 'वॉलेट और लेन-देन',
  'Clinician Panel': 'विशेषज्ञ पैनल',
  'Register New Child': 'नया बच्चा पंजीकृत करें',
  'Speech & Language Evaluation': 'बोली और भाषा मूल्यांकन',
  'Developmental Assessment': 'विकासात्मक मूल्यांकन',
  'Partner Dashboard': 'पार्टनर डैशबोर्ड',
  'App Portal': 'ऐप पोर्टल',

  // Logout modal
  'Log out?': 'लॉग आउट करें?',
  'Logging out…': 'लॉग आउट हो रहा है…',
  'Are you sure you want to log out of your account? You’ll need to sign in again to access evaluations and tools.':
    'क्या आप वाकई अपने अकाउंट से लॉग आउट करना चाहते हैं? मूल्यांकन और टूल्स इस्तेमाल करने के लिए आपको दोबारा साइन इन करना होगा।',

  // Dashboard — greeting & children
  'No children registered yet': 'अभी तक कोई बच्चा पंजीकृत नहीं',
  "Add your child's profile to begin evaluations and unlock growth modules.":
    'मूल्यांकन शुरू करने और ग्रोथ मॉड्यूल अनलॉक करने के लिए अपने बच्चे की प्रोफ़ाइल जोड़ें।',
  'Add My First Child': 'अपना पहला बच्चा जोड़ें',
  'yrs': 'वर्ष',
  'Mark Read': 'पढ़ा हुआ चिह्नित करें',
  'Note from clinician': 'विशेषज्ञ की ओर से नोट',

  // Dashboard — premium assessment cards
  'Voice-led adaptive conversation (~5 mins) evaluating articulation, fluency, and processing with real-time AI analysis.':
    'आवाज़-आधारित अनुकूल बातचीत (~5 मिनट) जो रियल-टाइम AI विश्लेषण के साथ उच्चारण, प्रवाह और प्रोसेसिंग का मूल्यांकन करती है।',
  'Start Speech Eval (₹1,000)': 'बोली मूल्यांकन शुरू करें (₹1,000)',
  '15-min guided parenting burden check-in. Includes written clinical reflection and a psychologist callback.':
    '15-मिनट का गाइडेड पैरेंटिंग बर्डन चेक-इन। इसमें लिखित क्लिनिकल रिफ्लेक्शन और मनोवैज्ञानिक की कॉलबैक शामिल है।',
  'Start Reflection (₹1,000)': 'रिफ्लेक्शन शुरू करें (₹1,000)',
  'Callback Scheduled': 'कॉलबैक शेड्यूल हो गई',
  'View Report': 'रिपोर्ट देखें',
  'Report': 'रिपोर्ट',

  // Care pack / child extras
  'Manage credits': 'क्रेडिट प्रबंधित करें',
  'Add credits': 'क्रेडिट जोड़ें',
  'Deduct': 'घटाएँ',
  'Confirm Unlock': 'अनलॉक की पुष्टि करें',
  'Amount': 'राशि',
  'Your balance': 'आपका बैलेंस',
  'credits': 'क्रेडिट',
};

// Regex rules for patterns with interpolated numbers/names. Applied after the
// exact dictionary, before falling back to AI. $1 etc. capture preserved parts.
export const HINDI_REGEX_RULES: Array<{ re: RegExp; to: string }> = [
  { re: /^Hi\s+(.+)$/i, to: 'नमस्ते $1' },
  { re: /^Hello\s+(.+)$/i, to: 'नमस्ते $1' },
  { re: /^(\d[\d,]*)\s*cr$/i, to: '$1 क्रेडिट' },
  { re: /^(\d[\d,]*)\s*credits?$/i, to: '$1 क्रेडिट' },
  { re: /^(\d+)\s*of\s*(\d+)\s*completed$/i, to: '$2 में से $1 पूरे' },
  { re: /^(\d+)\s*yrs?\s*old$/i, to: '$1 वर्ष' },
  { re: /^Grade\s+(.+)$/i, to: 'कक्षा $1' },
  { re: /^(\d+)\s*days?\s*remaining$/i, to: '$1 दिन बाकी' },
];

// Strings we must never translate (language switcher labels, etc.)
export const HINDI_SKIP = new Set<string>(['EN', 'हिं', 'HI']);
