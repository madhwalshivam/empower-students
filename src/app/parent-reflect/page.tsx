'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Mic,
  MicOff,
  Volume2,
  VolumeX,
  Play,
  Check,
  AlertCircle,
  Loader2,
  ArrowLeft,
  Sparkles,
  Heart,
  Calendar,
  PhoneCall,
  ShieldCheck,
  ChevronRight,
  ClipboardList,
  Wallet
} from 'lucide-react';
import {
  startReflectSession,
  submitReflectAnswer,
  finishReflectEarly,
  discardReflectSession,
  getReflectSessionReport,
  getLatestReflectReport,
} from '@/app/actions/reflect';

// Simple markdown-to-HTML parser for safety and styling
function parseMarkdown(md: string) {
  if (!md) return '';
  let html = md
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  // Headings
  html = html.replace(/^## (.*?)$/gm, '<h3 class="text-lg font-bold text-indigo-900 dark:text-indigo-405 mt-5 mb-2">$1</h3>');
  html = html.replace(/^# (.*?)$/gm, '<h2 class="text-xl font-bold text-indigo-950 dark:text-indigo-305 mt-6 mb-3">$1</h2>');

  // Bold
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong class="font-extrabold text-slate-900 dark:text-white">$1</strong>');

  // Bullet lists
  html = html.replace(/^\- (.*?)$/gm, '<li class="ml-4 list-disc text-slate-700 dark:text-slate-350">$1</li>');

  // Paragraphs
  html = html.split('\n\n').map(p => {
    if (p.trim().startsWith('<h') || p.trim().startsWith('<li')) return p;
    return `<p class="mb-3 leading-relaxed text-slate-650 dark:text-slate-300">${p.trim()}</p>`;
  }).join('\n');

  return html;
}

export default function ParentReflectPage() {
  const router = useRouter();

  // Screens: 'landing' | 'consent' | 'loading' | 'interview' | 'report'
  const [screen, setScreen] = useState<'landing' | 'consent' | 'loading' | 'interview' | 'report'>('landing');
  const [session, setSession] = useState<any>(null);
  const [currentTurn, setCurrentTurn] = useState<any>(null);
  const [report, setReport] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);

  // Consents State
  const [consents, setConsents] = useState({
    c1: false,
    c2: false,
    c3: false,
    c4: false,
  });

  // Wallet Error
  const [insufficientCredits, setInsufficientCredits] = useState<any>(null);

  // Live Interview State
  const [transcript, setTranscript] = useState('');
  const [isListening, setIsListening] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [statusMessage, setStatusMessage] = useState('');
  const [timeTaken, setTimeTaken] = useState(0);
  const [manualInput, setManualInput] = useState('');
  const [showManualInput, setShowManualInput] = useState(false);
  const [lastReflection, setLastReflection] = useState<string | null>(null);

  // Refs
  const recognitionRef = useRef<any>(null);
  const timerRef = useRef<any>(null);
  const silenceTimerRef = useRef<any>(null);

  // On mount: show completed report or resume in_progress session — so a
  // returning parent never sees the pay-gate for something they already paid for.
  useEffect(() => {
    (async () => {
      const existing = await getLatestReflectReport();
      if (!('error' in existing) && existing.parent_summary_md) {
        setReport(existing);
        setScreen('report');
        return;
      }
      // No completed report — land on the landing screen (existing in_progress
      // sessions are auto-resumed by startReflectSession when they click Begin).
      const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
      if (!SpeechRecognition) {
        setError('Web speech synthesis or mic inputs are not fully supported on this browser. You can still type your answers.');
      }
    })();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Timer increment
  useEffect(() => {
    if (screen === 'interview') {
      timerRef.current = setInterval(() => {
        setTimeTaken(prev => prev + 1);
      }, 1000);
    } else {
      if (timerRef.current) clearInterval(timerRef.current);
      setTimeTaken(0);
    }
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, [screen]);

  // Stop mic + clear timers on unmount to prevent stale callbacks after navigation.
  useEffect(() => {
    return () => {
      try { recognitionRef.current?.stop(); } catch {}
      if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
      if (timerRef.current) clearInterval(timerRef.current);
      window.speechSynthesis?.cancel();
    };
  }, []);

  // TTS speech output
  const speakQuestion = (text: string) => {
    if (isMuted || !text) return;
    try {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      const isHindi = /[\u0900-\u097F]/.test(text);
      utterance.lang = isHindi ? 'hi-IN' : 'en-IN';
      
      utterance.onstart = () => setIsSpeaking(true);
      utterance.onend = () => {
        setIsSpeaking(false);
        startListening();
      };
      utterance.onerror = () => {
        setIsSpeaking(false);
        startListening();
      };
      window.speechSynthesis.speak(utterance);
    } catch (e) {
      console.error('TTS failed:', e);
      setIsSpeaking(false);
      startListening();
    }
  };

  // Start Mic
  const startListening = () => {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition || isListening) return;

    try {
      const rec = new SpeechRecognition();
      rec.continuous = true;
      rec.interimResults = true;
      const isHindi = currentTurn?.question ? /[\u0900-\u097F]/.test(currentTurn.question) : false;
      rec.lang = isHindi ? 'hi-IN' : 'en-IN';

      rec.onstart = () => {
        setIsListening(true);
        setStatusMessage('Listening to your reflection...');
      };

      rec.onresult = (event: any) => {
        let finalTrans = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
          finalTrans += event.results[i][0].transcript;
        }
        setTranscript(finalTrans);

        // Speech pause timeout (3.5 seconds of silence = submit)
        if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
        silenceTimerRef.current = setTimeout(() => {
          handleSubmitAnswer(finalTrans);
        }, 3500);
      };

      rec.onerror = (e: any) => {
        console.error('Mic error:', e);
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed' || e.error === 'network') {
          setIsListening(false);
          setShowManualInput(true);
          setStatusMessage('Mic not available — please type your answer below.');
        }
      };

      rec.onend = () => {
        setIsListening(false);
      };

      recognitionRef.current = rec;
      rec.start();
    } catch (err) {
      console.error('Error starting recognition:', err);
      setShowManualInput(true);
      setStatusMessage('Mic not available — please type your answer below.');
    }
  };

  // Stop Mic
  const stopListening = () => {
    if (recognitionRef.current) {
      try {
        recognitionRef.current.stop();
      } catch (e) {}
    }
    if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    setIsListening(false);
  };

  const handleBegin = async () => {
    setScreen('loading');
    setError(null);
    setInsufficientCredits(null);

    const res = await startReflectSession();
    if ('error' in res) {
      if (res.error === 'insufficient') {
        setInsufficientCredits(res);
        setScreen('consent');
      } else {
        setError(res.error || 'Failed to start. Please try again.');
        setScreen('consent');
      }
    } else {
      setSession({ id: res.session_id });
      setCurrentTurn({
        turn_no: res.turn_no,
        phase: res.phase,
        question: res.question,
        options: res.options
      });
      setLastReflection(null);
      setScreen('interview');
      setStatusMessage('Preparing voice context...');
      setTimeout(() => {
        speakQuestion(res.question);
      }, 500);
    }
  };

  const handleSubmitAnswer = async (forcedText?: string) => {
    stopListening();
    const finalVal = (forcedText !== undefined ? forcedText : transcript || manualInput).trim();
    if (!finalVal) {
      setStatusMessage('Please speak or type a response.');
      return;
    }

    setScreen('loading');
    const timeUsed = timeTaken || 10;

    const acoustic = {
      duration_sec: timeUsed,
      wpm: finalVal.split(/\s+/).length / (timeUsed / 60 || 1),
      silence_ratio: 0.15,
      time_to_first_speech_sec: 1.2
    };

    const res = await submitReflectAnswer(
      session.id,
      currentTurn.turn_no,
      currentTurn.phase,
      finalVal,
      timeUsed,
      acoustic
    );

    if ('error' in res) {
      setError(res.error || 'Failed to process reflection.');
      setScreen('interview');
      return;
    }

    if (res.done) {
      // Completed report fetching
      const rep = await getReflectSessionReport(session.id);
      if ('error' in rep) {
        setError(rep.error || 'Report completed but failed to retrieve details.');
        setScreen('interview');
      } else {
        setReport(rep);
        setScreen('report');
      }
    } else {
      setLastReflection(res.reflection || null);
      setCurrentTurn(res.turn);
      setTranscript('');
      setManualInput('');
      setShowManualInput(false);
      setScreen('interview');
      setStatusMessage('Ready...');
      setTimeout(() => {
        if (res.turn) speakQuestion(res.turn.question);
      }, 800);
    }
  };

  const handleFinishEarly = async () => {
    if (window.confirm('Do you want to finalize your reflection right now? Our AI will compile your report based on the conversation so far.')) {
      setScreen('loading');
      await finishReflectEarly(session.id);
      const rep = await getReflectSessionReport(session.id);
      if ('error' in rep) {
        setError(rep.error || 'Failed to retrieve report.');
        setScreen('consent');
      } else {
        setReport(rep);
        setScreen('report');
      }
    }
  };

  const handlePause = async () => {
    if (window.confirm('Would you like to pause and save this session? You can resume it anytime within 24 hours.')) {
      router.push('/dashboard');
    }
  };

  const allConsentsChecked = consents.c1 && consents.c2 && consents.c3 && consents.c4;

  return (
    <div className="max-w-2xl mx-auto px-4 py-8">
      {/* 1. Landing Screen */}
      {screen === 'landing' && (
        <div className="space-y-6 animate-fade-in">
          <div className="bg-gradient-to-br from-indigo-600 to-purple-700 text-white rounded-3xl p-6 md:p-8 shadow-lg">
            <h1 className="heading-fun text-2xl md:text-3xl font-extrabold mb-2 text-white">
              A private space for you
            </h1>
            <p className="text-sm md:text-base leading-relaxed text-indigo-100">
              Parenting a child with developmental needs is hard — and most of the weight you carry, no one else sees.
              This is a 15-minute private reflection that helps you put words to what you're carrying,
              providing a warm written reflection and a follow-up call from our psychologist.
            </p>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <h2 className="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
              <ClipboardList className="text-indigo-650" size={20} /> What's included for ₹1,000
            </h2>
            
            <div className="space-y-4 text-sm text-slate-650 dark:text-slate-400">
              <div className="flex gap-3">
                <span className="text-2xl shrink-0">💬</span>
                <div>
                  <strong className="text-slate-800 dark:text-slate-200 block">10-15 Minute Private Conversation</strong>
                  Speak freely or select options. The AI counsellor will guide you gently through home, family dynamics, and your personal state.
                </div>
              </div>

              <div className="flex gap-3">
                <span className="text-2xl shrink-0">📋</span>
                <div>
                  <strong className="text-slate-800 dark:text-slate-200 block">Warm Written Reflection & Burden Check</strong>
                  Get a thoughtful analysis of your reflection, a detailed breakdown of 9 life areas, and practical action items.
                </div>
              </div>

              <div className="flex gap-3">
                <span className="text-2xl shrink-0">📞</span>
                <div>
                  <strong className="text-slate-800 dark:text-slate-200 block">Personal Psychologist Callback</strong>
                  A professional therapist will review your reflection and reach out for a 15-minute supportive call within 48 hours.
                </div>
              </div>
            </div>
          </div>

          <div className="bg-amber-50/50 dark:bg-slate-950/40 border border-amber-250 dark:border-amber-900/60 rounded-3xl p-5 text-xs text-amber-955 dark:text-amber-400 space-y-2">
            <h3 className="font-bold">A few honest things to know:</h3>
            <ul className="list-disc list-inside space-y-1">
              <li>This is a supportive reflection, not a clinical therapy or diagnostic tool.</li>
              <li>Your responses are secure and shared only with our clinical psychologist team.</li>
              <li>If you are in crisis, please contact local emergency helpline services immediately.</li>
            </ul>
          </div>

          <div className="text-center pt-2">
            <button
              onClick={() => setScreen('consent')}
              className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-8 py-3.5 rounded-2xl shadow-lg hover:scale-[1.02] transition-all inline-flex items-center gap-2"
            >
              Continue to Consent <ChevronRight size={16} />
            </button>
            <p className="text-xs text-slate-400 mt-2.5">Confirming details before starting session</p>
          </div>
        </div>
      )}

      {/* 2. Consent Screen */}
      {screen === 'consent' && (
        <div className="bg-white dark:bg-slate-900 border border-slate-150 dark:border-slate-800/80 rounded-3xl p-6 md:p-8 shadow-md space-y-6 animate-fade-in">
          <h1 className="heading-fun text-xl md:text-2xl font-bold text-slate-900 dark:text-white">
            Parent Consent & Wallet Authorization
          </h1>

          {error && (
            <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 text-rose-800 dark:text-rose-450 text-sm rounded-xl p-3 flex items-center gap-2">
              <AlertCircle size={16} />
              <span>{error}</span>
            </div>
          )}

          {insufficientCredits && (
            <div className="bg-amber-50 dark:bg-slate-950 border border-amber-200 dark:border-amber-900 rounded-2xl p-5 text-sm space-y-3">
              <p className="font-semibold text-amber-900 dark:text-amber-450 flex items-center gap-1.5">
                <Wallet size={16} /> Insufficient Wallet Balance
              </p>
              <p className="text-xs text-slate-650 dark:text-slate-400">
                This module requires <strong>{insufficientCredits.need} credits</strong> to complete.
                Your current balance is <strong>{insufficientCredits.balance} credits</strong>.
              </p>
              <Link
                href={`/wallet?need=${insufficientCredits.need}`}
                className="inline-flex items-center gap-1 bg-amber-600 text-white font-bold text-xs px-4 py-2.5 rounded-xl hover:bg-amber-700 transition"
              >
                Top Up Wallet <ChevronRight size={14} />
              </Link>
            </div>
          )}

          <div className="bg-indigo-50 dark:bg-slate-950 border border-indigo-100 dark:border-slate-805 rounded-2xl p-4 text-xs sm:text-sm text-slate-655 dark:text-slate-405">
            <span className="font-bold block text-indigo-700 dark:text-indigo-400 mb-1">Fee: ₹1,000 (Refundable if unsatisfied)</span>
            Credits are reserved now and charged ONLY when you successfully complete the 15-minute voice interview.
          </div>

          <div className="space-y-4">
            <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Please tick these to begin</h3>
            <div className="space-y-3 text-sm text-slate-700 dark:text-slate-350">
              <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={consents.c1}
                  onChange={(e) => setConsents({ ...consents, c1: e.target.checked })}
                  className="mt-1 cursor-pointer accent-indigo-650"
                />
                <span>I understand this is a <strong>private reflection</strong>, not medical diagnosis or formal therapy.</span>
              </label>

              <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={consents.c2}
                  onChange={(e) => setConsents({ ...consents, c2: e.target.checked })}
                  className="mt-1 cursor-pointer accent-indigo-650"
                />
                <span>I understand the AI guides are <strong>not licensed therapists</strong>. If I need immediate help, I will call support groups.</span>
              </label>

              <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={consents.c3}
                  onChange={(e) => setConsents({ ...consents, c3: e.target.checked })}
                  className="mt-1 cursor-pointer accent-indigo-650"
                />
                <span>I consent to having the EmpowerStudents clinical psychologists review this reflection and call me within 48 hours.</span>
              </label>

              <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={consents.c4}
                  onChange={(e) => setConsents({ ...consents, c4: e.target.checked })}
                  className="mt-1 cursor-pointer accent-indigo-650"
                />
                <span>I will be in a relatively quiet place where I can speak freely for ~15 minutes.</span>
              </label>
            </div>
          </div>

          <div className="border-t border-slate-100 dark:border-slate-850 pt-6 flex justify-between items-center">
            <button
              onClick={() => setScreen('landing')}
              className="text-slate-400 hover:text-slate-650 text-sm font-bold"
            >
              ← Back
            </button>
            <button
              onClick={handleBegin}
              disabled={!allConsentsChecked}
              className="bg-indigo-650 hover:bg-indigo-750 text-white font-bold px-6 py-3 rounded-xl disabled:opacity-40 disabled:cursor-not-allowed transition"
            >
              Begin Reflection
            </button>
          </div>
        </div>
      )}

      {/* 3. Loading screen */}
      {screen === 'loading' && (
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-12 text-center shadow-md space-y-4 flex flex-col items-center justify-center min-h-[300px]">
          <Loader2 className="animate-spin text-indigo-650" size={40} />
          <h3 className="font-bold text-slate-800 dark:text-slate-200">Reflecting...</h3>
          <p className="text-xs text-slate-400 dark:text-slate-500">Claude AI is compiling responses</p>
        </div>
      )}

      {/* 4. Live Reflection Interview */}
      {screen === 'interview' && currentTurn && (
        <div className="space-y-6 animate-fade-in">
          {/* Header strip */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div>
              <span className="bg-indigo-50 dark:bg-slate-950 text-indigo-750 dark:text-indigo-400 font-extrabold text-xs px-2.5 py-1 rounded-lg">
                Turn {currentTurn.turn_no}
              </span>
              <span className="text-xs font-bold text-slate-550 ml-2">Phase {currentTurn.phase}/10</span>
            </div>
            <div className="flex gap-2">
              <button
                onClick={handleFinishEarly}
                className="bg-emerald-50 dark:bg-slate-950 text-emerald-700 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-950 font-bold text-xs px-3 py-1.5 rounded-lg"
              >
                Finish Now
              </button>
              <button
                onClick={handlePause}
                className="bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-bold text-xs px-3 py-1.5 rounded-lg"
              >
                Pause
              </button>
            </div>
          </div>

          <div className="bg-white dark:bg-slate-900 border-2 border-indigo-100 dark:border-slate-800 rounded-3xl p-6 md:p-8 text-center min-h-[420px] flex flex-col items-center justify-between shadow-md relative">
            
            {/* Mirror reflection statement if any */}
            {lastReflection && (
              <div className="w-full bg-indigo-50/50 dark:bg-slate-950/60 border border-indigo-100 dark:border-slate-800 rounded-2xl p-4 text-left mb-4">
                <span className="text-[10px] uppercase font-bold tracking-widest text-indigo-650 dark:text-indigo-450 block mb-1">
                  A Moment of Reflection
                </span>
                <p className="text-sm text-slate-700 dark:text-slate-350 leading-relaxed italic">
                  "{lastReflection}"
                </p>
              </div>
            )}

            <div className="my-auto space-y-6 w-full">
              {/* Question */}
              <h2 className="text-lg md:text-xl font-bold text-slate-950 dark:text-slate-100 leading-relaxed px-4">
                {currentTurn.question}
              </h2>

              {/* Quick option chips if available */}
              {currentTurn.options && currentTurn.options.length > 0 && (
                <div className="flex flex-wrap gap-2 justify-center">
                  {currentTurn.options.map((opt: string) => (
                    <button
                      key={opt}
                      onClick={() => {
                        setTranscript(opt);
                        handleSubmitAnswer(opt);
                      }}
                      className="bg-indigo-50 hover:bg-indigo-100 dark:bg-slate-950 dark:hover:bg-slate-850 text-indigo-750 dark:text-indigo-350 font-bold text-xs py-2 px-4 rounded-xl transition border border-indigo-100/50 dark:border-slate-800"
                    >
                      {opt}
                    </button>
                  ))}
                </div>
              )}

              {/* Live Transcript / Hearing Box */}
              {isListening && (
                <div className="w-full bg-rose-50/50 dark:bg-slate-950/60 border border-rose-100 dark:border-slate-800 rounded-2xl p-4 text-left animate-fade-in">
                  <span className="text-[10px] uppercase font-bold tracking-widest text-rose-650 dark:text-rose-450 block mb-1">
                    Hearing
                  </span>
                  <p className="text-sm text-slate-700 dark:text-slate-300 italic min-h-[1.5rem]">
                    {transcript || 'Speak naturally now...'}
                  </p>
                </div>
              )}

              {/* Typable fallback */}
              {showManualInput && (
                <div className="w-full space-y-3 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                  <label className="block text-xs text-left font-bold text-slate-400 uppercase">
                    Type Response Fallback
                  </label>
                  <textarea
                    rows={3}
                    value={manualInput}
                    onChange={(e) => setManualInput(e.target.value)}
                    placeholder="Type your reflection here..."
                    className="w-full px-4 py-3 rounded-xl border border-slate-205 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-202 outline-none focus:border-indigo-500 text-sm resize-none"
                  />
                </div>
              )}
            </div>

            {/* Controls */}
            <div className="w-full pt-6 flex flex-col sm:flex-row gap-3 items-center justify-center border-t border-slate-55 dark:border-slate-855">
              {!showManualInput ? (
                <>
                  <button
                    onClick={() => speakQuestion(currentTurn.question)}
                    className="w-full sm:w-auto bg-slate-100 dark:bg-slate-800 hover:bg-slate-205 text-slate-700 dark:text-slate-300 font-bold text-xs py-3 px-5 rounded-xl transition animate-fade-in"
                  >
                    🔊 Hear AI Again
                  </button>
                  <button
                    onClick={() => handleSubmitAnswer()}
                    disabled={!transcript}
                    className="w-full sm:w-auto bg-indigo-650 hover:bg-indigo-750 text-white font-bold text-xs py-3 px-6 rounded-xl transition disabled:opacity-40 flex items-center gap-1.5 justify-center"
                  >
                    <Check size={14} /> Done Speaking
                  </button>
                  <button
                    onClick={() => {
                      stopListening();
                      setShowManualInput(true);
                    }}
                    className="text-xs text-indigo-650 dark:text-indigo-400 font-bold hover:underline mt-2 sm:mt-0"
                  >
                    Type Response
                  </button>
                </>
              ) : (
                <>
                  <button
                    onClick={() => {
                      setShowManualInput(false);
                      setManualInput('');
                      startListening();
                    }}
                    className="w-full sm:w-auto bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-750 dark:text-slate-300 font-bold text-xs py-3 px-5 rounded-xl transition"
                  >
                    🎙️ Use Microphone
                  </button>
                  <button
                    onClick={() => handleSubmitAnswer()}
                    disabled={!manualInput.trim()}
                    className="w-full sm:w-auto bg-indigo-650 hover:bg-indigo-755 text-white font-bold text-xs py-3 px-6 rounded-xl transition disabled:opacity-40"
                  >
                    Submit Response
                  </button>
                </>
              )}
            </div>

          </div>
        </div>
      )}

      {/* 5. Report view */}
      {screen === 'report' && report && (
        <div className="space-y-6 animate-fade-in">
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <h1 className="heading-fun text-2xl font-bold text-slate-900 dark:text-white">
              Parent Reflection Summary
            </h1>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button
                onClick={() => { setReport(null); setSession(null); setScreen('landing'); setConsents({ c1: false, c2: false, c3: false, c4: false }); }}
                style={{ background: '#f1f5f9', color: '#4f46e5', fontWeight: 700, fontSize: 12, padding: '8px 14px', borderRadius: 12, border: 'none', cursor: 'pointer' }}
              >
                New Reflection
              </button>
              <Link
                href="/dashboard"
                className="bg-indigo-50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 text-indigo-700 dark:text-indigo-400 font-bold text-xs px-4 py-2 rounded-xl"
              >
                Dashboard
              </Link>
            </div>
          </div>

          {/* Callback Scheduled confirmation */}
          <div className="bg-emerald-500 text-white rounded-3xl p-6 shadow-lg flex items-center gap-4">
            <div className="bg-white/10 p-3 rounded-2xl border border-white/15">
              <PhoneCall size={28} />
            </div>
            <div>
              <h2 className="font-extrabold text-lg text-white">Psychologist Call Scheduled</h2>
              <p className="text-xs opacity-90 mt-0.5">One of our family counselors will review your reflection and reach out for a 15-minute voice call within 48 hours.</p>
            </div>
          </div>

          {/* Summary Markdown */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6 shadow-sm space-y-4">
            <h3 className="font-extrabold text-slate-900 dark:text-white text-base flex items-center gap-2 border-b border-slate-50 dark:border-slate-850 pb-3">
              <Heart className="text-indigo-650" size={18} /> Supportive Written Reflection
            </h3>
            <div
              className="prose dark:prose-invert max-w-none text-sm leading-relaxed"
              dangerouslySetInnerHTML={{ __html: parseMarkdown(report.parent_summary_md) }}
            />
          </div>

          {/* 9 Structured Life Areas Grid */}
          {report.v3_listing && (
            <div className="space-y-4">
              <h3 className="font-extrabold text-slate-800 dark:text-slate-200 text-sm flex items-center gap-1.5">
                <ShieldCheck className="text-indigo-600" size={18} /> Parenting Burden Index (9 Life Areas)
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                {Object.entries(report.v3_listing).map(([key, item]: [string, any]) => (
                  <div
                    key={key}
                    className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-4 shadow-sm flex flex-col justify-between"
                  >
                    <div>
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-xl">{item.emoji || '🌱'}</span>
                        <h4 className="font-extrabold text-slate-900 dark:text-slate-200 text-xs line-clamp-1">
                          {item.label_en || key}
                        </h4>
                      </div>
                      <p className="text-[10px] text-slate-450 dark:text-slate-500 leading-normal line-clamp-3">
                        {item.insight || 'Not probed in detail.'}
                      </p>
                    </div>
                    <div className="pt-3 flex items-center justify-between text-[10px] border-t border-slate-50 dark:border-slate-850 mt-3">
                      <span className="text-slate-400 font-bold uppercase">Burden Score</span>
                      <span className={`font-black text-xs px-2 py-0.5 rounded-md ${Number(item.score || 0) >= 7 ? 'bg-rose-50 text-rose-700 dark:bg-slate-950' : 'bg-indigo-50 text-indigo-750 dark:bg-slate-950'}`}>
                        {item.score || 0}/10
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
