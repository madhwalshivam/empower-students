'use server';

import { createClient } from '@/lib/supabase/server';
import { createAdminClient } from '@/lib/supabase/admin';
import { claudeChat, claudeJson } from '@/lib/claude/client';
import { calcAgeYears } from '@/lib/evaluations/engine';
import fs from 'fs';
import path from 'path';

const SPEECH_PRICE = 1000; // 1000 credits
const HAIKU_MODEL = process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-5';
const SONNET_MODEL = process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-5';

// Canned question bank matching PHP includes/eval_engine.php
function getCannedQuestion(
  level: number,
  isHindi: boolean,
  askedPrompts: string[] = []
): { type: string; prompt: string; expected: string } {
  const bank: Record<number, { en: Array<{type: string, prompt: string, expected: string}>, hi: Array<{type: string, prompt: string, expected: string}> }> = {
    1: {
      en: [
        { type: 'naming', prompt: 'Tell me, what animal goes "meow meow"?', expected: 'cat|kitty|billi' },
        { type: 'naming', prompt: 'What do we drink that is white and we get from a cow?', expected: 'milk|doodh' },
        { type: 'naming', prompt: 'What red round fruit do we eat?', expected: 'apple|seb' },
        { type: 'naming', prompt: 'What animal says "woof woof"?', expected: 'dog|kutta|puppy' },
        { type: 'naming', prompt: 'Tell me — what do we wear on our feet?', expected: 'shoes|chappal|slippers|socks|joote' },
        { type: 'naming', prompt: 'What is the color of the sky?', expected: 'blue|neela|neela rang' },
        { type: 'naming', prompt: 'What do we use to see with our eyes?', expected: 'eyes|seeing|spectacles|aankh' },
        { type: 'naming', prompt: 'What animal has a long trunk and is very big?', expected: 'elephant|haathi' },
        { type: 'naming', prompt: 'Which body part do we use to hear sound?', expected: 'ears|ear|kaan' },
      ],
      hi: [
        { type: 'naming', prompt: 'बच्चे, कौन-सा जानवर "म्याऊँ-म्याऊँ" करता है?', expected: 'बिल्ली|cat|billi' },
        { type: 'naming', prompt: 'सफ़ेद रंग का जो हम गिलास में पीते हैं, वो क्या है?', expected: 'दूध|milk|doodh' },
        { type: 'naming', prompt: 'लाल गोल मीठा फल कौन-सा होता है?', expected: 'सेब|apple|seb' },
        { type: 'naming', prompt: 'कौन-सा जानवर "भौं-भौं" करता है?', expected: 'कुत्ता|dog|kutta' },
        { type: 'naming', prompt: 'पैरों में हम क्या पहनते हैं?', expected: 'जूते|चप्पल|shoes|joote|chappal' },
        { type: 'naming', prompt: 'आसमान का रंग कैसा होता है?', expected: 'नीला|blue|neela' },
        { type: 'naming', prompt: 'हाथ में कितनी उंगलियां होती हैं?', expected: 'पांच|five|paanch' },
        { type: 'naming', prompt: 'कौन सा बड़ा जानवर है जिसकी एक लंबी सूंड होती है?', expected: 'हाथी|elephant|haathi' },
        { type: 'naming', prompt: 'हम अपने कानों से क्या करते हैं?', expected: 'सुनते|सुनना|hear|listen' },
      ],
    },
    2: {
      en: [
        { type: 'naming', prompt: 'Tell me — what do we eat in the morning for breakfast?', expected: 'roti|bread|paratha|cereal|toast|breakfast|milk|doodh' },
        { type: 'naming', prompt: 'Where does a bird fly? Tell me one place.', expected: 'sky|aasman|aasmaan|air|tree' },
        { type: 'naming', prompt: 'What do we use to write on paper?', expected: 'pen|pencil|kalam' },
        { type: 'naming', prompt: 'When it rains, what do we hold over our head?', expected: 'umbrella|chhata|chhatri' },
        { type: 'naming', prompt: 'What animal lives in water and swims?', expected: 'fish|machhli' },
        { type: 'naming', prompt: 'What do we brush our teeth with?', expected: 'toothbrush|brush|paste' },
        { type: 'naming', prompt: 'Which animal is known as the king of the jungle?', expected: 'lion|sher' },
        { type: 'naming', prompt: 'What do we sleep on at night?', expected: 'bed|pillow|bistar|gadda' },
        { type: 'naming', prompt: 'What do you use to cut paper or thread?', expected: 'scissors|scissor' },
      ],
      hi: [
        { type: 'naming', prompt: 'सुबह नाश्ते में हम क्या खाते हैं?', expected: 'रोटी|पराठा|दूध|breakfast|paratha|roti' },
        { type: 'naming', prompt: 'चिड़िया कहाँ उड़ती है? बताओ।', expected: 'आसमान|aasman|sky|पेड़' },
        { type: 'naming', prompt: 'काग़ज़ पर लिखने के लिए हम क्या इस्तेमाल करते हैं?', expected: 'पेंसिल|पेन|कलम|pencil|pen' },
        { type: 'naming', prompt: 'जब बारिश होती है, तो सिर के ऊपर हम क्या रखते हैं?', expected: 'छाता|छतरी|umbrella' },
        { type: 'naming', prompt: 'पानी में रहने वाला जानवर जो तैरता है — वो कौन है?', expected: 'मछली|fish|machhli' },
        { type: 'naming', prompt: 'दांत साफ़ करने के लिए हम क्या इस्तेमाल करते हैं?', expected: 'ब्रश|पेस्ट|brush|toothpaste' },
        { type: 'naming', prompt: 'जंगल का राजा किस जानवर को कहा जाता है?', expected: 'शेर|lion|sher' },
        { type: 'naming', prompt: 'रात को हम किस चीज़ पर सोते हैं?', expected: 'बिस्तर|खाट|bed|bistar' },
        { type: 'naming', prompt: 'काग़ज़ काटने के लिए हम क्या इस्तेमाल करते हैं?', expected: 'कैंची|scissors|kainchi' },
      ],
    },
    3: {
      en: [
        { type: 'naming', prompt: 'Where does mummy cook food in the house? Tell me.', expected: 'kitchen|rasoi' },
        { type: 'naming', prompt: 'Tell me about your favourite toy. What is it?', expected: 'car|doll|ball|teddy|train|bike|any toy' },
        { type: 'naming', prompt: 'Where do cars and buses run? Tell me.', expected: 'road|street|sadak|highway' },
        { type: 'naming', prompt: 'What do we do at night when we feel sleepy?', expected: 'sleep|sona|rest|go to bed' },
        { type: 'naming', prompt: 'Name any one fruit that is yellow.', expected: 'banana|kela|mango|aam|lemon|nimbu' },
        { type: 'naming', prompt: 'Which vehicle has two wheels and we pedal it?', expected: 'bicycle|cycle' },
        { type: 'naming', prompt: 'What do we wear on our hands when it is very cold?', expected: 'gloves|glove|dastaane' },
        { type: 'naming', prompt: 'Where do we go to study and meet our teachers?', expected: 'school|skool' },
        { type: 'naming', prompt: 'What shines brightly in the sky during the day?', expected: 'sun|suraj' },
      ],
      hi: [
        { type: 'naming', prompt: 'घर में मम्मी खाना कहाँ बनाती हैं? बताओ।', expected: 'रसोई|किचन|kitchen|rasoi' },
        { type: 'naming', prompt: 'अपने पसंदीदा खिलौने के बारे में बताओ — वो क्या है?', expected: 'गाड़ी|गुड़िया|बॉल|कोई भी खिलौना|toy|car|doll|ball' },
        { type: 'naming', prompt: 'गाड़ियाँ और बस कहाँ चलती हैं? बताओ।', expected: 'सड़क|रोड|road|sadak' },
        { type: 'naming', prompt: 'रात को जब नींद आती है, तब हम क्या करते हैं?', expected: 'सोते|सोना|sleep|sona' },
        { type: 'naming', prompt: 'कोई एक पीला फल बताओ।', expected: 'केला|आम|नींबू|banana|mango|kela' },
        { type: 'naming', prompt: 'दो पहियों वाली गाड़ी जिसे हम पैरों से चलाते हैं, वो क्या है?', expected: 'साइकिल|cycle|bicycle' },
        { type: 'naming', prompt: 'ठंड में हम अपने हाथों में क्या पहनते हैं?', expected: 'दस्ताने|gloves|dastaane' },
        { type: 'naming', prompt: 'हम पढ़ाई करने और शिक्षकों से मिलने कहाँ जाते हैं?', expected: 'स्कूल|school' },
        { type: 'naming', prompt: 'दिन में आसमान में क्या तेज़ी से चमकता है?', expected: 'सूरज|सूरजदेव|सूर्य|sun|suraj' },
      ],
    },
    4: {
      en: [
        { type: 'describe', prompt: 'Tell me what happens in the morning when you wake up — what do you do first?', expected: 'brush teeth|wash face|eat breakfast|any morning routine activity' },
        { type: 'describe', prompt: 'Tell me about your school. What do you do there?', expected: 'study|read|write|play|learn|any school activity' },
        { type: 'describe', prompt: 'If your friend is crying, what would you do to make them feel better?', expected: 'hug|talk|share|help|comfort|any kindness' },
        { type: 'describe', prompt: 'Why do we wear an umbrella? Tell me.', expected: 'rain|wet|barish|protection from rain|any reason involving rain' },
        { type: 'describe', prompt: 'Tell me what you did yesterday — anything you remember.', expected: 'any activity|played|ate|watched|any memory' },
        { type: 'describe', prompt: 'Tell me, what happens if we do not water a small plant?', expected: 'die|wilt|dry|सूख जाएगा' },
        { type: 'describe', prompt: 'If you see a lot of garbage on the floor, what should you do?', expected: 'dustbin|clean|throw|कचरा पेटी' },
        { type: 'describe', prompt: 'Tell me how you make a paper boat or a paper plane.', expected: 'fold|paper|making|मोड़ना' },
      ],
      hi: [
        { type: 'describe', prompt: 'सुबह उठकर तुम सबसे पहले क्या करते हो? बताओ।', expected: 'ब्रश|मुँह धोना|नाश्ता|कोई भी सुबह की दिनचर्या' },
        { type: 'describe', prompt: 'अपने स्कूल के बारे में बताओ। वहाँ तुम क्या करते हो?', expected: 'पढ़ाई|खेल|दोस्त|any school activity' },
        { type: 'describe', prompt: 'अगर तुम्हारा दोस्त रो रहा हो, तो तुम उसे क्या करोगे?', expected: 'गले लगाना|बात करना|शेयर|मदद|कोई भी अच्छाई|hug|help' },
        { type: 'describe', prompt: 'हम छाता क्यों लेकर जाते हैं? बताओ।', expected: 'बारिश|भीगना|barish|rain|कोई भी कारण' },
        { type: 'describe', prompt: 'कल तुमने क्या किया? कुछ भी जो तुम्हें याद है, बताओ।', expected: 'कोई भी काम|खेला|खाया|देखा|any memory' },
        { type: 'describe', prompt: 'अगर हम किसी छोटे पौधे में पानी न डालें, तो क्या होगा?', expected: 'सूख जाएगा|मर जाएगा|die|dry' },
        { type: 'describe', prompt: 'अगर फ़र्श पर बहुत सारा कचरा पड़ा हो, तो तुम्हें क्या करना चाहिए?', expected: 'कचरा पेटी|साफ़|dustbin|clean' },
        { type: 'describe', prompt: 'काग़ज़ की नाव या हवाई जहाज़ कैसे बनाते हैं, बताओ।', expected: 'मोड़कर|काग़ज़|fold|paper' },
      ],
    },
    5: {
      en: [
        { type: 'describe', prompt: 'Tell me a short story about a tiger who went into the forest.', expected: 'any coherent story with beginning/middle/end about a tiger' },
        { type: 'describe', prompt: 'What is honesty? Can you give me an example?', expected: 'truth|not lying|telling the truth|any example of honesty' },
        { type: 'describe', prompt: 'If you could be any animal for one day, which would you be and why?', expected: 'any animal with a reason' },
        { type: 'describe', prompt: 'Tell me about a time you helped someone — what happened?', expected: 'any helping story' },
        { type: 'describe', prompt: 'What is the difference between a fruit and a vegetable?', expected: 'fruit is sweet|vegetable for cooking|any reasonable distinction' },
        { type: 'describe', prompt: 'Why is it important to brush our teeth two times a day?', expected: 'cavity|germs|clean|healthy|कीड़ा' },
        { type: 'describe', prompt: 'What would you do if you found a lost pencil in your classroom?', expected: 'teacher|owner|give back|दोस्त को देना' },
        { type: 'describe', prompt: 'Tell me about your favourite cartoon or movie character and why you like them.', expected: 'cartoon|character|hero|any response' },
      ],
      hi: [
        { type: 'describe', prompt: 'एक छोटी-सी कहानी सुनाओ — एक शेर जंगल में गया।', expected: 'कोई भी सुसंगत कहानी शुरुआत-मध्य-अंत के साथ' },
        { type: 'describe', prompt: 'सच्चाई क्या होती है? कोई उदाहरण दो।', expected: 'सच बोलना|झूठ नहीं बोलना|कोई भी उदाहरण' },
        { type: 'describe', prompt: 'अगर तुम एक दिन के लिए कोई भी जानवर बन सकते, तो कौन-सा और क्यों?', expected: 'कोई भी जानवर कारण के साथ' },
        { type: 'describe', prompt: 'एक बार बताओ जब तुमने किसी की मदद की — क्या हुआ था?', expected: 'कोई भी मदद की कहानी' },
        { type: 'describe', prompt: 'फल और सब्ज़ी में क्या अंतर है?', expected: 'फल मीठा होता है|सब्ज़ी पकाते हैं|कोई भी अंतर' },
        { type: 'describe', prompt: 'दिन में दो बार ब्रश करना क्यों ज़रूरी है?', expected: 'कीड़ा|साफ़|स्वस्थ|cavity|clean' },
        { type: 'describe', prompt: 'अगर तुम्हें क्लास में किसी की खोई हुई पेंसिल मिले, तो तुम क्या करोगे?', expected: 'टीचर को देंगे|वापस करेंगे|teacher' },
        { type: 'describe', prompt: 'अपने पसंदीदा कार्टून या फ़िल्म के किरदार के बारे में बताओ और वो तुम्हें क्यों पसंद है?', expected: 'कार्टून|नायक|hero|cartoon' },
      ],
    },
  };

  const cleanLevel = Math.max(1, Math.min(5, level));
  const set = isHindi ? bank[cleanLevel].hi : bank[cleanLevel].en;
  
  // Filter out questions already asked in this session
  const available = set.filter(q => !askedPrompts.includes(q.prompt));
  if (available.length > 0) {
    const idx = Math.floor(Math.random() * available.length);
    return available[idx];
  }
  
  const idx = Math.floor(Math.random() * set.length);
  return set[idx];
}

function getSpeechLevelDesc(level: number): { name: string; desc: string; age_eq: string } {
  const levels: Record<number, { name: string; desc: string; age_eq: string }> = {
    1: {
      name: 'Single sounds & basic words',
      desc: 'Single phonemes (M, B, P, T, K, S sounds), basic vowels, very common single-syllable words like "cat", "ball", "milk".',
      age_eq: '~18 months to 3 years equivalent',
    },
    2: {
      name: 'Common nouns & simple phrases',
      desc: 'Combines 2-3 words, names common objects, uses basic descriptors (big, hot, red). Words are identifiable but may have minor phonetic errors.',
      age_eq: '~3 to 4 years equivalent',
    },
    3: {
      name: 'Short sentences & basic grammar',
      desc: 'Full simple sentences ("I want milk", "bird flies in sky"). Uses basic plurals, pronouns (he/she/me), and simple verbs correctly.',
      age_eq: '~4 to 6 years equivalent',
    },
    4: {
      name: 'Complex sentences & sequencing',
      desc: 'Explains multi-step routines, tells simple chronological events ("yesterday we went to park and played"). Uses connecting words like "because", "and then".',
      age_eq: '~6 to 8 years equivalent',
    },
    5: {
      name: 'Narration & abstract vocabulary',
      desc: 'Narration of stories with basic plot structure, explains abstract terms (feelings, kindness), uses sophisticated vocabulary and complex sentence structures.',
      age_eq: '~8 to 12 years equivalent',
    },
  };
  return levels[level] || levels[3];
}

async function generateSpeechQuestionAI(
  sessionId: number,
  level: number,
  child: any,
  seqNo: number,
  supabaseAdmin: any
): Promise<{ type: string; prompt: string; expected: string; level: number } | null> {
  const ageYrs = calcAgeYears(child.dob);
  const lvlInfo = getSpeechLevelDesc(level);
  const isHindi = child.mother_tongue?.toLowerCase().includes('hindi') || child.mother_tongue === 'hi';

  // Pull recent question history to avoid repeats and let AI see context
  const { data: history } = await supabaseAdmin
    .from('eval_questions')
    .select('seq_no, level, question_type, prompt, user_answer, is_correct, time_seconds')
    .eq('session_id', sessionId)
    .order('seq_no', { ascending: false })
    .limit(5);

  const historyLines = (history || []).reverse().map((h: any) => {
    const verdict = h.is_correct === null ? '?' : (h.is_correct ? '✓' : '✗');
    return `  Q${h.seq_no} (L${h.level}, ${h.question_type}): "${h.prompt.substring(0, 80)}" → answered "${(h.user_answer || '').substring(0, 50)}" ${verdict} (${h.time_seconds}s)`;
  });

  const historyText = historyLines.length === 0 ? "  (this is the first question)" : historyLines.join('\n');

  const sys = `You generate ONE adaptive speech & language evaluation question for a child to answer ALOUD. `
    + `This is a live voice interview — the child will hear the question read aloud (TTS) and speak their answer back.\n\n`
    + `STRICT RULES:\n`
    + `- NO multiple choice. NO 'pick from options'. The child will speak freely.\n`
    + `- Question must be ANSWERABLE BY SPEAKING in 1-2 short sentences.\n`
    + `- NO 'type your answer' / 'write' / 'pick' wording — say 'tell me' / 'say' / 'name' / 'bolo' / 'batao'.\n`
    + `- Indian context: Indian names (Aarav, Priya, Rahul), foods (रोटी, दाल), family (दीदी, दादी), settings (स्कूल, मंदिर, बाज़ार).\n`
    + `- LANGUAGE — VERY IMPORTANT FOR TTS:\n`
    + `    - If mother tongue is Hindi: write in **Devanagari ONLY** (शुद्ध हिंदी). Romanized Hindi sounds wrong in TTS.\n`
    + `    - If mother tongue is English: write in **plain English ONLY** (no Hinglish).\n`
    + `    - For other languages: default to plain English.\n`
    + `- Question must MATCH level L${level} — not too easy, not too hard.\n`
    + `- Don't repeat any concept from recent history.\n`
    + `- Be warm and brief. Like a friendly therapist talking to a child, not a test.\n\n`
    + `QUESTION TYPES (pick the most natural for spoken answer):\n`
    + `  - 'naming': ask a direct question; child says one word or short phrase as answer.\n`
    + `      English example: 'Tell me, what do we eat at breakfast?'\n`
    + `      Hindi example:   'बच्चे, हम सुबह नाश्ते में क्या खाते हैं?'\n`
    + `  - 'describe': open question child answers in 1-2 sentences. Use L4+.\n`
    + `      English example: 'Tell me about your favourite festival.'\n`
    + `      Hindi example:   'अपने पसंदीदा त्योहार के बारे में बताओ।'\n\n`
    + `DO NOT use fill-in-the-blank questions (sentences with ___). They confuse young children.\n\n`
    + `Output JSON only:\n`
    + `{\n`
    + `  "type": "naming" | "describe",\n`
    + `  "prompt": "the question text — short, conversational, designed to be SPOKEN aloud",\n`
    + `  "expected": "acceptable answer(s), separated by | if multiple. Be generous. For Hindi questions, list both Devanagari and Romanized forms (e.g. 'दूध|doodh|milk')"\n`
    + `}`;

  const usr = `Child profile:\n`
    + `  Name: ${child.name}\n`
    + `  Age: ${ageYrs} years\n`
    + `  Gender: ${child.gender || 'unspecified'}\n`
    + `  Mother tongue: ${child.mother_tongue || 'English'}\n\n`
    + `Current level: L${level} — ${lvlInfo.name}\n`
    + `Level description: ${lvlInfo.desc}\n`
    + `Age-equivalent: ${lvlInfo.age_eq}\n\n`
    + `Recent history:\n${historyText}\n\n`
    + `Generate Q${seqNo} now. Return JSON only.`;

  try {
    let q = await claudeJson(sys, usr, 400, 0.5, HAIKU_MODEL);
    if (!q || !q.prompt) {
      q = await claudeJson(sys, usr, 400, 0.5, SONNET_MODEL);
    }
    if (q && q.prompt) {
      return {
        type: q.type || 'naming',
        prompt: q.prompt,
        expected: q.expected || '',
        level,
      };
    }
  } catch (e) {
    console.error('[generateSpeechQuestionAI] Claude error:', e);
  }
  return null;
}

export async function startSpeechEvalSession(childId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { error: 'Not signed in' };
  }

  const supabaseAdmin = createAdminClient();

  // Verify child belongs to parent
  const { data: child, error: childErr } = await supabaseAdmin
    .from('children')
    .select('*')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .single();

  if (childErr || !child) {
    return { error: 'Please select a valid child.' };
  }

  // Resume check: any in_progress session within 7 days. 30-min was too short —
  // parents often navigate away mid-eval (child distracted, phone call, etc.) and
  // lose their paid session. 7 days keeps paid sessions alive long enough to finish.
  const sevenDaysAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString();
  const { data: existing } = await supabaseAdmin
    .from('eval_sessions')
    .select('*')
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .eq('module', 'mod_speech_basic')
    .eq('status', 'in_progress')
    .gt('started_at', sevenDaysAgo)
    .order('id', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (existing && Number(existing.questions_asked) > 0) {
    // Retrieve the current open question in this session
    const { data: openQ } = await supabaseAdmin
      .from('eval_questions')
      .select('*')
      .eq('session_id', existing.id)
      .eq('seq_no', existing.questions_asked)
      .single();

    if (openQ) {
      return {
        session_id: existing.id,
        question: {
          question_id: openQ.id,
          seq_no: openQ.seq_no,
          level: openQ.level,
          type: openQ.question_type,
          prompt: openQ.prompt,
          image_concept: openQ.image_concept,
        },
      };
    }
  }

  // If there was an old in-progress session, abandon it
  if (existing) {
    await supabaseAdmin
      .from('eval_sessions')
      .update({ status: 'abandoned' })
      .eq('id', existing.id);
  }

  // Determine if parent qualifies for a free evaluation (free_eval_used_at is null)
  const { data: parent } = await supabaseAdmin
    .from('parents')
    .select('free_eval_used_at, credits')
    .eq('id', user.id)
    .single();

  // "Once purchased, always purchased" — if any previous paid session exists for
  // this child (completed OR abandoned), the parent already paid. New attempts are
  // free so they can finish without being charged twice.
  const { count: prevPaidCount } = await supabaseAdmin
    .from('eval_sessions')
    .select('id', { count: 'exact', head: true })
    .eq('parent_id', user.id)
    .eq('child_id', childId)
    .eq('module', 'mod_speech_basic')
    .gt('cost_paid', 0);

  const alreadyPaid = (prevPaidCount || 0) > 0;

  const isFree = !parent?.free_eval_used_at || alreadyPaid;
  let costPaid = 0;

  if (!isFree) {
    const credits = parent?.credits || 0;
    if (credits < SPEECH_PRICE) {
      return {
        error: 'insufficient',
        need: SPEECH_PRICE,
        balance: credits,
      };
    }

    const nextCredits = credits - SPEECH_PRICE;

    // Deduct credits
    await supabaseAdmin
      .from('parents')
      .update({ credits: nextCredits })
      .eq('id', user.id);

    costPaid = SPEECH_PRICE;
  }

  // Create new session
  const { data: newSession, error: sErr } = await supabaseAdmin
    .from('eval_sessions')
    .insert({
      parent_id: user.id,
      child_id: childId,
      module: 'mod_speech_basic',
      status: 'in_progress',
      is_free: isFree,
      cost_paid: costPaid,
      current_level: 3,
      questions_asked: 1,
    })
    .select('id')
    .single();

  if (sErr || !newSession) {
    console.error('Session create error:', sErr);
    return { error: 'Could not start evaluation. Please try again.' };
  }

  if (isFree) {
    // Consume free token
    await supabaseAdmin
      .from('parents')
      .update({ free_eval_used_at: new Date().toISOString() })
      .eq('id', user.id);
  }

  if (costPaid > 0) {
    // Ledger entry
    await supabaseAdmin.from('wallet_ledger').insert({
      parent_id: user.id,
      amount: -SPEECH_PRICE,
      balance_after: (parent?.credits || 0) - SPEECH_PRICE,
      service_key: 'mod_speech_eval',
      ref_id: newSession.id,
      reason: `Speech & Language Evaluation for child ID ${childId}`,
    });
  }

  // Seed first question: try AI generator first, fallback to canned level 3
  const isHindi = child.mother_tongue?.toLowerCase().includes('hindi') || child.mother_tongue === 'hi';
  let nextQ = await generateSpeechQuestionAI(newSession.id, 3, child, 1, supabaseAdmin);
  
  if (!nextQ) {
    const canned = getCannedQuestion(3, isHindi, []);
    nextQ = {
      type: canned.type,
      prompt: canned.prompt,
      expected: canned.expected,
      level: 3,
    };
  }

  const questionType = nextQ.type;
  const questionPrompt = nextQ.prompt;
  const questionExpected = nextQ.expected;

  const { data: newQ, error: qErr } = await supabaseAdmin
    .from('eval_questions')
    .insert({
      session_id: newSession.id,
      seq_no: 1,
      level: 3,
      question_type: questionType,
      prompt: questionPrompt,
      expected: questionExpected,
    })
    .select('id')
    .single();

  if (qErr || !newQ) {
    console.error('Question create error:', qErr);
    return { error: 'Could not generate first question.' };
  }

  return {
    session_id: newSession.id,
    question: {
      question_id: newQ.id,
      seq_no: 1,
      level: 3,
      type: questionType,
      prompt: questionPrompt,
      image_concept: null,
    },
  };
}

export async function submitSpeechAnswer(
  sessionId: number,
  questionId: number,
  transcript: string,
  timeSeconds: number,
  acoustic: any,
  audioFileBase64?: string
) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) {
    return { error: 'Not signed in' };
  }

  const supabaseAdmin = createAdminClient();

  // Validate session ownership
  const { data: session, error: sErr } = await supabaseAdmin
    .from('eval_sessions')
    .select(`
      *,
      children (
        name,
        dob,
        gender,
        mother_tongue
      )
    `)
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (sErr || !session || session.status !== 'in_progress') {
    return { error: 'Session not found or already completed.' };
  }

  // Fetch current question
  const { data: q, error: qErr } = await supabaseAdmin
    .from('eval_questions')
    .select('*')
    .eq('id', questionId)
    .eq('session_id', sessionId)
    .single();

  if (qErr || !q) {
    return { error: 'Question not found.' };
  }

  const curLevel = Number(q.level);
  const ageYrs = calcAgeYears(session.children.dob);
  const seqSoFar = Number(q.seq_no);

  // Optional: save audio file locally
  let audioPath = null;
  if (audioFileBase64) {
    try {
      const buffer = Buffer.from(audioFileBase64, 'base64');
      const uploadDir = path.join(process.cwd(), 'public', 'uploads', 'eval');
      if (!fs.existsSync(uploadDir)) {
        fs.mkdirSync(uploadDir, { recursive: true });
      }
      const fname = `q${questionId}_${Date.now()}.webm`;
      const dest = path.join(uploadDir, fname);
      fs.writeFileSync(dest, buffer);
      audioPath = `/uploads/eval/${fname}`;
    } catch (e) {
      console.error('Audio write error:', e);
    }
  }

  // Pull recent history
  const { data: history } = await supabaseAdmin
    .from('eval_questions')
    .select('*')
    .eq('session_id', sessionId)
    .lt('seq_no', seqSoFar)
    .not('is_correct', 'is', null)
    .order('seq_no', { ascending: false })
    .limit(5);

  const historyLines = (history || []).reverse().map(h => {
    const verdict = h.is_correct ? '✓' : '✗';
    return `  Q${h.seq_no} (L${h.level}): "${h.prompt.substring(0, 70)}" → "${h.user_answer?.substring(0, 40)}" ${verdict} (${h.time_seconds}s)`;
  });
  const historyText = historyLines.length === 0 ? '  (no prior questions)' : historyLines.join('\n');

  // Build acoustic details
  let acousticBlock = '';
  const isVoice = acoustic && Object.keys(acoustic).length > 0 && transcript !== '';
  if (isVoice) {
    const sig = [];
    if (acoustic.transcript_confidence) sig.push(`transcript confidence ${Math.round(acoustic.transcript_confidence * 100)}%`);
    if (acoustic.duration_sec) sig.push(`spoke for ${Math.round(acoustic.duration_sec * 10) / 10}s`);
    if (acoustic.wpm) sig.push(`${Math.round(acoustic.wpm)} WPM`);
    if (acoustic.silence_ratio) sig.push(`${Math.round(acoustic.silence_ratio * 100)}% silence`);
    if (acoustic.time_to_first_speech_sec) sig.push(`took ${Math.round(acoustic.time_to_first_speech_sec * 10) / 10}s to start`);
    acousticBlock = `\n\nAcoustic signals (child SPOKE this answer): ${sig.join(', ')}.`;
  }

  const sys = `You are running an adaptive speech & language voice interview for an Indian child. `
    + `ONE call from you must do TWO things:\n\n`
    + `TASK 1 — SCORE the child's answer to the previous question.\n`
    + `  - Be lenient on Indian English spelling, speech-to-text artifacts, child shorthand.\n`
    + `  - Accept semantic equivalents.\n\n`
    + `TASK 2 — GENERATE TWO candidate next questions:\n`
    + `  - 'q_harder':  question one level above current (for if the answer was correct & fast)\n`
    + `  - 'q_same':    question at the SAME level (for if mixed/slow/wrong)\n`
    + `  Server picks the right one based on its own logic.\n\n`
    + `QUESTION GENERATION RULES:\n`
    + `  - NO multiple choice. NO 'pick from options'. Voice interview only.\n`
    + `  - Question must be answerable by SPEAKING in 1-2 short sentences.\n`
    + `  - NO 'type' / 'write' / 'pick' wording — say 'tell me' / 'say' / 'name' / 'bolo' / 'batao'.\n`
    + `  - Indian context: Indian names, foods (रोटी, दाल), family (दीदी, दादी), settings (स्कूल, मंदिर, बाज़ार).\n`
    + `  - LANGUAGE — VERY IMPORTANT FOR TTS:\n`
    + `      - If mother tongue is Hindi: write the question in **Devanagari ONLY**. Romanized Hindi sounds wrong (TTS spells letter-by-letter).\n`
    + `      - If mother tongue is English: write in **plain English ONLY**.\n`
    + `  - Don't repeat any concept from recent history.\n`
    + `  - Be warm and brief.\n\n`
    + `QUESTION TYPES (use ONLY these two):\n`
    + `  - 'naming': ask a direct question; child says one word or short phrase. ('हम सुबह नाश्ते में क्या खाते हैं?')\n`
    + `  - 'describe': open question for L4+. ('अपने पसंदीदा त्योहार के बारे में बताओ।')\n\n`
    + `DO NOT use fill-in-the-blank questions (sentences with ___). They confuse young/disabled children.\n\n`
    + `LEVELS — generate q_harder and q_same accordingly.\n`
    + `  L1: single sounds, basic words (~18mo-3yr)\n`
    + `  L2: common nouns, 2-word phrases (~3-4yr)\n`
    + `  L3: short sentences, basic grammar (~4-6yr)\n`
    + `  L4: complex sentences, sequencing (~6-8yr)\n`
    + `  L5: advanced narration, abstract vocab (~8-12yr)\n\n`
    + `OUTPUT — JSON only, no fences, no extra text:\n`
    + `{\n`
    + `  "is_correct": true|false,\n`
    + `  "reason": "one short sentence",\n`
    + `  "q_harder": {\n`
    + `    "type": "naming"|"describe",\n`
    + `    "prompt": "...",\n`
    + `    "expected": "answer1|answer2|...",\n`
    + `    "level": ${Math.min(5, curLevel + 1)}\n`
    + `  },\n`
    + `  "q_same": {\n`
    + `    "type": "naming"|"describe",\n`
    + `    "prompt": "...",\n`
    + `    "expected": "answer1|answer2|...",\n`
    + `    "level": ${curLevel}\n`
    + `  }\n`
    + `}`;

  const usr = `Child: ${session.children.name}, age ${ageYrs}, mother tongue: ${session.children.mother_tongue || 'English'}\n`
    + `Current level: L${curLevel}\n\n`
    + `Recent history:\n${historyText}\n\n`
    + `JUST-ASKED QUESTION (Q${seqSoFar}): ${q.prompt}\n`
    + `  Type: ${q.question_type}\n`
    + `  Expected: ${q.expected}\n`
    + `  Child's answer: "${transcript || '(no response)'}"\n`
    + `  Response time: ${timeSeconds}s`
    + acousticBlock
    + `\n\nNow score AND generate the two candidate next questions. Return JSON only.`;

  // Combined call to Claude (Haiku if available, fallback to Sonnet)
  let j = await claudeJson(sys, usr, 800, 0.5, HAIKU_MODEL);
  if (!j || typeof j.is_correct === 'undefined') {
    // Try Sonnet fallback
    j = await claudeJson(sys, usr, 800, 0.5, SONNET_MODEL);
  }

  // Determine scoring
  const isCorrect = j ? !isEmpty(j.is_correct) && j.is_correct === true : false;
  const fast = timeSeconds <= 8;
  let verdict = 'wrong_slow';
  if (isCorrect && fast) verdict = 'correct_fast';
  else if (isCorrect && !fast) verdict = 'correct_slow';
  else if (!isCorrect && fast) verdict = 'wrong_fast';

  let nextLevel = curLevel;
  if (verdict === 'correct_fast') nextLevel = Math.min(5, curLevel + 1);
  else if (verdict === 'wrong_slow') nextLevel = Math.max(1, curLevel - 1);

  // Stop check: 15 cap or 3 consecutive same verdicts at same level after 8 questions
  let shouldStop = false;
  let stopReason = '';
  if (seqSoFar >= 15) {
    shouldStop = true;
    stopReason = 'hit hard cap of 15 questions';
  } else if (seqSoFar >= 8 && history) {
    const last2 = history.slice(0, 2);
    if (last2.length === 2 && last2[0].level === curLevel && last2[1].level === curLevel) {
      const allCorrect = isCorrect && last2[0].is_correct === 1 && last2[1].is_correct === 1;
      const allWrong = !isCorrect && last2[0].is_correct === 0 && last2[1].is_correct === 0;
      if (allCorrect || allWrong) {
        shouldStop = true;
        stopReason = `3 consecutive at L${curLevel} with consistent ${allCorrect ? 'correct' : 'incorrect'}`;
      }
    }
  }

  // Update current question
  await supabaseAdmin
    .from('eval_questions')
    .update({
      answered_at: new Date().toISOString(),
      time_seconds: timeSeconds,
      user_answer: transcript || '(no response)',
      answer_mode: isVoice ? 'voice' : 'text',
      acoustic_json: isVoice ? JSON.stringify(acoustic) : null,
      is_correct: isCorrect ? 1 : 0,
      ai_verdict: verdict,
      next_level: nextLevel,
      audio_path: audioPath,
    })
    .eq('id', questionId);

  if (shouldStop) {
    // Run finalization
    await finalizeSpeechSession(sessionId, supabaseAdmin);
    const { data: finalSession } = await supabaseAdmin
      .from('eval_sessions')
      .select('*')
      .eq('id', sessionId)
      .single();

    return {
      should_stop: true,
      report: {
        final_level: finalSession.final_level,
        final_pct: finalSession.final_pct,
        questions_asked: finalSession.questions_asked,
        report_md: finalSession.report_md,
        sample_exercise_md: finalSession.sample_exercise_md,
        final_level_name: getSpeechLevelDesc(finalSession.final_level).name,
        child_id: finalSession.child_id,
      },
    };
  }

  // Choose next question: try using candidate from API response first, fallback to fresh AI generation, fallback to canned
  let nextQ = null;
  if (j) {
    const candidate = nextLevel > curLevel ? j.q_harder : j.q_same;
    if (candidate && candidate.prompt) {
      nextQ = candidate;
    }
  }

  if (!nextQ) {
    nextQ = await generateSpeechQuestionAI(sessionId, nextLevel, session.children, seqSoFar + 1, supabaseAdmin);
  }

  if (!nextQ) {
    const isHindi = session.children.mother_tongue?.toLowerCase().includes('hindi') || session.children.mother_tongue === 'hi';
    const { data: asked } = await supabaseAdmin
      .from('eval_questions')
      .select('prompt')
      .eq('session_id', sessionId);
    const askedPrompts = (asked || []).map((q: any) => q.prompt);
    nextQ = getCannedQuestion(nextLevel, isHindi, askedPrompts);
  }

  const newSeq = seqSoFar + 1;
  const { data: insertedQ } = await supabaseAdmin
    .from('eval_questions')
    .insert({
      session_id: sessionId,
      seq_no: newSeq,
      level: nextLevel,
      question_type: nextQ.type,
      prompt: nextQ.prompt,
      expected: nextQ.expected,
    })
    .select('*')
    .single();

  await supabaseAdmin
    .from('eval_sessions')
    .update({
      current_level: nextLevel,
      questions_asked: newSeq,
    })
    .eq('id', sessionId);

  return {
    should_stop: false,
    last_correct: isCorrect,
    question: {
      question_id: insertedQ.id,
      seq_no: newSeq,
      level: nextLevel,
      type: insertedQ.question_type,
      prompt: insertedQ.prompt,
      image_concept: null,
    },
  };
}

async function finalizeSpeechSession(sessionId: number, supabaseAdmin: any) {
  const { data: session } = await supabaseAdmin
    .from('eval_sessions')
    .select(`
      *,
      children (
        name,
        dob,
        gender,
        mother_tongue
      )
    `)
    .eq('id', sessionId)
    .single();

  const { data: questions } = await supabaseAdmin
    .from('eval_questions')
    .select('*')
    .eq('session_id', sessionId)
    .order('seq_no', { ascending: true });

  if (!questions || questions.length === 0) return;

  const tail = questions.slice(-3);
  const finalLevel = Math.round(tail.reduce((acc: number, item: any) => acc + Number(item.level), 0) / tail.length);

  const right = questions.reduce((acc: number, item: any) => acc + (item.is_correct === 1 ? 1 : 0), 0);
  const finalPct = Math.round((100 * right) / questions.length);

  const ageYrs = calcAgeYears(session.children.dob);
  const lvlInfo = getSpeechLevelDesc(finalLevel);

  const histLines = questions.map((q: any) => {
    const v = q.is_correct === 1 ? '✓' : '✗';
    return `  Q${q.seq_no} L${q.level} (${q.question_type}): ${q.prompt} → "${q.user_answer || ''}" ${v} (${q.time_seconds}s)`;
  });
  const histText = histLines.join('\n');

  const sys = `You are a senior speech-language pathologist at EmpowerStudents — a clinical service `
    + `providing evaluations AND professional therapy programs (Speech Therapy, language `
    + `intervention, Occupational Therapy) for Indian children.\n\n`
    + `Write a parent-facing report for this speech & language evaluation. Tone: empathetic, `
    + `expert, reassuring, action-oriented. Position therapy as something WE will do for `
    + `the child (in person at our centre or via video sessions), with parents playing a `
    + `small daily supportive role at home (5 mins/day) — NOT the primary teaching role.\n\n`
    + `Output JSON only:\n`
    + `{\n`
    + `  "report_md": "## Where {ChildName} is now\\n... ## Strengths ... ## Areas to develop ... `
    + `## Recommended next step\\nSentence inviting the parent to start our 1-week speech plan `
    + `(₹99) for personalised daily practice + check-ins from our therapists. ",\n`
    + `  "sample_exercise_md": "## Today's sample exercise (free preview)\\n A SINGLE concrete `
    + `10-minute activity the parent can do TODAY with their child to build the next-level skill. `
    + `Specific, Indian context, age-appropriate. Format: ### What you need ### How to play ### What to watch for"\n`
    + `}\n\n`
    + `RULES:\n`
    + `- report_md under 350 words. sample_exercise_md under 250 words.\n`
    + `- Be specific about the level (L1-L5) using the level name, not just the number.\n`
    + `- DO NOT mention specific prices for therapy in the report — only mention the ₹99 weekly plan.\n`
    + `- Use child's actual name throughout. Indian context.\n`
    + `- No reasoning trace. Final clean text only.\n`;

  const usr = `Child: ${session.children.name}, age ${ageYrs} yrs, `
    + `${session.children.gender || 'unspecified'}, mother tongue: `
    + `${session.children.mother_tongue || 'English'}\n\n`
    + `Final level reached: L${finalLevel} — ${lvlInfo.name}\n`
    + `Level description: ${lvlInfo.desc}\n`
    + `Age-equivalent: ${lvlInfo.age_eq}\n\n`
    + `Overall accuracy: ${right}/${questions.length} (${finalPct}%)\n`
    + `Total questions: ${questions.length}\n\n`
    + `Full question history:\n${histText}\n\n`
    + `Now generate the report. JSON only.`;

  const j = await claudeJson(sys, usr, 1800, 0.5, SONNET_MODEL);

  await supabaseAdmin
    .from('eval_sessions')
    .update({
      status: 'completed',
      completed_at: new Date().toISOString(),
      final_level: finalLevel,
      final_pct: finalPct,
      report_md: j?.report_md || 'Unable to generate report at this time.',
      sample_exercise_md: j?.sample_exercise_md || 'Unable to generate sample exercise at this time.',
    })
    .eq('id', sessionId);
}

export async function cancelSpeechSession(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  await supabaseAdmin
    .from('eval_sessions')
    .update({ status: 'abandoned' })
    .eq('id', sessionId)
    .eq('parent_id', user.id);

  return { ok: true };
}

export async function getSpeechSessionReport(sessionId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('eval_sessions')
    .select(`
      *,
      children (
        name,
        dob
      )
    `)
    .eq('id', sessionId)
    .eq('parent_id', user.id)
    .single();

  if (!session || session.status !== 'completed') {
    return { error: 'Report not ready' };
  }

  return {
    final_level: session.final_level,
    final_pct: session.final_pct,
    questions_asked: session.questions_asked,
    report_md: session.report_md,
    sample_exercise_md: session.sample_exercise_md,
    final_level_name: getSpeechLevelDesc(session.final_level).name,
    child_id: session.child_id,
    child_name: session.children?.name,
  };
}

// Returns the latest speech session for this parent+child regardless of status.
// Used by the dashboard and the eval-speech page to decide what to show:
//   completed  → show report
//   in_progress → show resume
//   abandoned   → show "start again" (credits already used)
export async function getLatestSpeechSession(childId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('eval_sessions')
    .select('id, child_id, status, final_level, final_pct, questions_asked, started_at, completed_at, amount_paid, is_free')
    .eq('child_id', childId)
    .eq('parent_id', user.id)
    .eq('module', 'mod_speech_basic')
    .order('started_at', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (!session) return { error: 'No session' };
  return session;
}

// Parent-wide version — used by the dashboard so "My Purchases" appears
// regardless of which child is currently selected in the child switcher.
export async function getLatestSpeechSessionForParent() {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('eval_sessions')
    .select('id, child_id, status, final_level, final_pct, questions_asked, started_at, completed_at')
    .eq('parent_id', user.id)
    .eq('module', 'mod_speech_basic')
    .order('started_at', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (!session) return { error: 'No session' };
  return session;
}

// Latest COMPLETED speech report for a child the parent owns — used to show a
// returning parent their purchased report instead of the pay-gate.
export async function getLatestSpeechReportForChild(childId: number) {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { error: 'Not signed in' };

  const supabaseAdmin = createAdminClient();
  const { data: session } = await supabaseAdmin
    .from('eval_sessions')
    .select(`
      *,
      children (
        name,
        dob
      )
    `)
    .eq('child_id', childId)
    .eq('parent_id', user.id)
    .eq('module', 'mod_speech_basic')
    .eq('status', 'completed')
    .order('completed_at', { ascending: false })
    .limit(1)
    .maybeSingle();

  if (!session) {
    return { error: 'No report' };
  }

  return {
    final_level: session.final_level,
    final_pct: session.final_pct,
    questions_asked: session.questions_asked,
    report_md: session.report_md,
    sample_exercise_md: session.sample_exercise_md,
    final_level_name: getSpeechLevelDesc(session.final_level).name,
    child_id: session.child_id,
    child_name: session.children?.name,
  };
}

function isEmpty(val: any): boolean {
  return val === null || val === undefined || val === '';
}

// Check if speech eval is permanently unlocked for this child
export async function isSpeechEvalUnlocked(childId: number): Promise<boolean> {
  const supabase = await createClient();
  const { data: { user }, error } = await supabase.auth.getUser();
  if (error || !user) return false;
  const db = createAdminClient();
  const { count } = await db
    .from('wallet_ledger')
    .select('*', { count: 'exact', head: true })
    .eq('parent_id', user.id)
    .eq('service_key', 'speech_eval_unlock')
    .eq('ref_id', childId);
  return (count || 0) > 0;
}

// Permanently unlock speech eval for a child — deducts 1000 credits once.
// After this, the child's eval page opens directly without a payment gate.
export async function unlockSpeechEvalAction(
  childId: number
): Promise<{ ok: boolean; error?: string; insufficient?: boolean; need?: number; balance?: number }> {
  const supabase = await createClient();
  const { data: { user }, error: authErr } = await supabase.auth.getUser();
  if (authErr || !user) return { ok: false, error: 'Please log in again.' };

  const db = createAdminClient();

  const { data: child } = await db
    .from('children')
    .select('id, name')
    .eq('id', childId)
    .eq('parent_id', user.id)
    .maybeSingle();
  if (!child) return { ok: false, error: 'Child not found.' };

  // Already unlocked?
  if (await isSpeechEvalUnlocked(childId)) return { ok: true };

  const { data: parent } = await db.from('parents').select('credits').eq('id', user.id).single();
  const balance = parent?.credits || 0;
  const PRICE = 1000;
  if (balance < PRICE) return { ok: false, insufficient: true, need: PRICE, balance, error: 'Insufficient credits.' };

  const newBalance = balance - PRICE;
  await db.from('parents').update({ credits: newBalance }).eq('id', user.id);

  await db.from('wallet_ledger').insert({
    parent_id: user.id,
    amount: -PRICE,
    balance_after: newBalance,
    service_key: 'speech_eval_unlock',
    ref_id: childId,
    reason: `Speech & Language Evaluation unlocked for ${child.name}`,
  });

  const { revalidatePath } = await import('next/cache');
  revalidatePath(`/child/${childId}`);
  revalidatePath('/dashboard');
  return { ok: true, balance: newBalance };
}
