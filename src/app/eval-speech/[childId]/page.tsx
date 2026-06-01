'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useParams, useRouter } from 'next/navigation';
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
  Clock,
  User,
  Wallet,
  CheckCircle2,
  XCircle
} from 'lucide-react';
import {
  startSpeechEvalSession,
  submitSpeechAnswer,
  cancelSpeechSession,
  getSpeechSessionReport
} from '@/app/actions/speech';

// Simple markdown-to-HTML parser for safety and styling
function parseMarkdown(md: string) {
  if (!md) return '';
  let html = md
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  // Headings
  html = html.replace(/^## (.*?)$/gm, '<h3 class="text-lg font-bold text-indigo-900 dark:text-indigo-400 mt-5 mb-2">$1</h3>');
  html = html.replace(/^# (.*?)$/gm, '<h2 class="text-xl font-bold text-indigo-950 dark:text-indigo-300 mt-6 mb-3">$1</h2>');

  // Bold
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong class="font-extrabold text-slate-900 dark:text-white">$1</strong>');

  // Bullet lists
  html = html.replace(/^\- (.*?)$/gm, '<li class="ml-4 list-disc text-slate-700 dark:text-slate-350">$1</li>');

  // Paragraphs
  html = html.split('\n\n').map(p => {
    if (p.trim().startsWith('<h') || p.trim().startsWith('<li')) return p;
    return `<p class="mb-3 leading-relaxed text-slate-600 dark:text-slate-300">${p.trim()}</p>`;
  }).join('\n');

  return html;
}

export default function SpeechEvalPage() {
  const router = useRouter();
  const params = useParams();
  const childId = Number(params.childId);

  // Flow screens: 'unsupported' | 'gate' | 'loading' | 'interview' | 'report'
  const [screen, setScreen] = useState<'unsupported' | 'gate' | 'loading' | 'interview' | 'report'>('loading');
  
  const [session, setSession] = useState<any>(null);
  const [currentQuestion, setCurrentQuestion] = useState<any>(null);
  const [report, setReport] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);

  // Wallet logic
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

  // Refs
  const recognitionRef = useRef<any>(null);
  const timerRef = useRef<any>(null);
  const silenceTimerRef = useRef<any>(null);
  const audioStartTimeRef = useRef<number>(0);

  // Browser support check
  useEffect(() => {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      setScreen('unsupported');
    } else {
      setScreen('gate');
    }
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

  // Audio prompt TTS
  const speakPrompt = (text: string) => {
    if (isMuted || !text) return;
    try {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      
      // Determine language
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
      console.error('TTS synthesis failed:', e);
      setIsSpeaking(false);
      startListening();
    }
  };

  // Start Mic Listening
  const startListening = () => {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition || isListening) return;

    try {
      const rec = new SpeechRecognition();
      rec.continuous = true;
      rec.interimResults = true;
      
      // Hindi prompt detection for recognition language selection
      const isHindi = currentQuestion?.prompt ? /[\u0900-\u097F]/.test(currentQuestion.prompt) : false;
      rec.lang = isHindi ? 'hi-IN' : 'en-IN';

      rec.onstart = () => {
        setIsListening(true);
        setStatusMessage('Listening to your child...');
        audioStartTimeRef.current = Date.now();
      };

      rec.onresult = (event: any) => {
        let finalTrans = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
          if (event.results[i].isFinal) {
            finalTrans += event.results[i][0].transcript;
          } else {
            finalTrans += event.results[i][0].transcript;
          }
        }
        setTranscript(finalTrans);

        // Auto-submit silence timeout (3 seconds of no new speech)
        if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
        silenceTimerRef.current = setTimeout(() => {
          handleDoneSpeaking(finalTrans);
        }, 3000);
      };

      rec.onerror = (e: any) => {
        console.error('Speech recognition error:', e);
        if (e.error === 'no-speech') {
          setStatusMessage('We didn\'t hear anything. Try again.');
        } else {
          setStatusMessage(`Mic issue: ${e.error}`);
        }
      };

      rec.onend = () => {
        setIsListening(false);
      };

      recognitionRef.current = rec;
      rec.start();
    } catch (err) {
      console.error('Error starting recognition:', err);
    }
  };

  // Stop Mic Listening
  const stopListening = () => {
    if (recognitionRef.current) {
      try {
        recognitionRef.current.stop();
      } catch (e) {}
    }
    if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    setIsListening(false);
  };

  const handleStart = async () => {
    setScreen('loading');
    setError(null);
    setInsufficientCredits(null);

    const res = await startSpeechEvalSession(childId);
    if ('error' in res) {
      if (res.error === 'insufficient_credits') {
        setInsufficientCredits(res);
        setScreen('gate');
      } else {
        setError(res.error || 'Failed to start. Please check your internet connection.');
        setScreen('gate');
      }
    } else {
      setSession({ id: res.session_id });
      setCurrentQuestion(res.question);
      setScreen('interview');
      setTranscript('');
      setStatusMessage('Setting up voice...');
      setTimeout(() => {
        speakPrompt(res.question.prompt);
      }, 500);
    }
  };

  const handleDoneSpeaking = async (finalTranscript?: string) => {
    stopListening();
    const finalVal = (finalTranscript !== undefined ? finalTranscript : transcript || manualInput).trim();
    if (!finalVal) {
      setStatusMessage('Please speak or type an answer to continue.');
      return;
    }

    setScreen('loading');
    const timeUsed = timeTaken || 5;

    // Simulate basic acoustic markers since standard SpeechRecognition doesn't provide them
    const acoustic = {
      wpm: finalVal.split(/\s+/).length / (timeUsed / 60 || 1),
      time_to_first_speech_sec: 1.5,
      transcript_confidence: 0.92
    };

    const res = await submitSpeechAnswer(
      session.id,
      currentQuestion.question_id,
      finalVal,
      timeUsed,
      acoustic
    );

    if ('error' in res) {
      setError(res.error || 'Failed to submit answer.');
      setScreen('interview');
      return;
    }

    if (res.should_stop) {
      setReport(res.report);
      setScreen('report');
    } else {
      setCurrentQuestion(res.question);
      setTranscript('');
      setManualInput('');
      setShowManualInput(false);
      setScreen('interview');
      setStatusMessage('Ready for next question...');
      setTimeout(() => {
        speakPrompt(res.question.prompt);
      }, 500);
    }
  };

  const handleCancel = async () => {
    if (window.confirm('Are you sure you want to end this evaluation early? Your current progress will not be saved.')) {
      if (session?.id) {
        await cancelSpeechSession(session.id);
      }
      router.push('/dashboard');
    }
  };

  const toggleMute = () => {
    setIsMuted(!isMuted);
    if (!isMuted) {
      window.speechSynthesis.cancel();
    } else {
      speakPrompt(currentQuestion?.prompt);
    }
  };

  return (
    <div className="max-w-2xl mx-auto px-4 py-8">
      {/* Unsupported Browser Gate */}
      {screen === 'unsupported' && (
        <div className="bg-amber-50 dark:bg-slate-900 border-2 border-amber-300 dark:border-amber-900/60 rounded-3xl p-8 text-center shadow-lg animate-fade-in">
          <div className="text-6xl mb-4">🎙️</div>
          <h1 className="text-2xl font-bold text-amber-900 dark:text-amber-450 mb-3">
            Voice evaluation needs a compatible browser
          </h1>
          <p className="text-sm text-slate-650 dark:text-slate-400 mb-6 max-w-md mx-auto">
            This evaluation listens to your child speaking. Your current browser doesn't support live voice recognition.
          </p>
          <div className="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-6 text-left text-sm text-slate-700 dark:text-slate-400 space-y-3 mb-6 shadow-inner">
            <p className="font-bold text-slate-900 dark:text-slate-200">Please open this page in:</p>
            <ul className="list-disc list-inside space-y-1.5 text-xs">
              <li><strong>Google Chrome</strong> (Android, iOS, Windows, Mac)</li>
              <li><strong>Microsoft Edge</strong> (Windows, Android)</li>
              <li><strong>Apple Safari</strong> (iOS 14.5+)</li>
            </ul>
            <p className="text-[11px] text-slate-400 mt-2">Firefox and some default webview engines are currently unsupported.</p>
          </div>
          <Link href="/dashboard" className="text-indigo-650 font-bold hover:underline">
            ← Back to Parent Dashboard
          </Link>
        </div>
      )}

      {/* Gate / Pre-eval Gate */}
      {screen === 'gate' && (
        <div className="bg-white dark:bg-slate-900 border border-slate-150 dark:border-slate-800/80 rounded-3xl p-6 md:p-8 shadow-md space-y-6">
          <div className="flex items-center gap-3">
            <span className="text-3xl">🎤</span>
            <div>
              <h1 className="heading-fun text-2xl font-bold text-slate-900 dark:text-white">
                Speech & Language Evaluation
              </h1>
              <p className="text-xs text-slate-400 mt-0.5">Premium Interactive Diagnostics</p>
            </div>
          </div>

          <p className="text-sm text-slate-500 leading-relaxed">
            This is an adaptive voice-led conversation containing 5 to 12 questions (~5 minutes).
            Your child will listen to each question played aloud by the AI therapist, then speak their response.
            At the end, you'll receive a detailed clinical analysis and targeted home exercises.
          </p>

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
                You need <strong>{insufficientCredits.need} credits</strong> to start this evaluation.
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

          <div className="bg-indigo-50 dark:bg-slate-950 border border-indigo-100 dark:border-slate-800 rounded-2xl p-5 flex items-center justify-between">
            <div>
              <p className="text-xs text-indigo-700 dark:text-indigo-400 font-bold uppercase tracking-wider">Evaluation Fee</p>
              <p className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-0.5">₹1,000</p>
            </div>
            <div className="text-right">
              <span className="text-[10px] text-slate-400 uppercase tracking-widest block font-bold">Charged at start</span>
              <span className="text-xs font-semibold text-indigo-650 dark:text-indigo-400 mt-0.5 block">100% Refundable if unsatisfied</span>
            </div>
          </div>

          <div className="border-t border-slate-100 dark:border-slate-850 pt-6 flex flex-col sm:flex-row gap-3 items-center justify-between">
            <Link href="/dashboard" className="text-slate-400 hover:text-slate-600 text-sm font-bold flex items-center gap-1">
              <ArrowLeft size={16} /> Cancel
            </Link>
            <button
              onClick={handleStart}
              className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-8 py-3.5 rounded-2xl shadow-lg hover:scale-[1.02] transition-all flex items-center gap-2 w-full sm:w-auto justify-center"
            >
              <Play size={16} /> Start Evaluation
            </button>
          </div>
        </div>
      )}

      {/* Loading Overlay */}
      {screen === 'loading' && (
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-12 text-center shadow-md space-y-4 flex flex-col items-center justify-center min-h-[300px]">
          <Loader2 className="animate-spin text-indigo-650" size={40} />
          <h3 className="font-bold text-slate-800 dark:text-slate-200">Processing...</h3>
          <p className="text-xs text-slate-400 dark:text-slate-500">Communicating with speech evaluation servers</p>
        </div>
      )}

      {/* Live Voice Evaluation Interview */}
      {screen === 'interview' && currentQuestion && (
        <div className="space-y-6 animate-fade-in">
          {/* Header strip */}
          <div className="bg-white dark:bg-slate-900 border border-slate-105 dark:border-slate-800 rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="bg-indigo-50 dark:bg-slate-950 text-indigo-700 dark:text-indigo-400 font-extrabold text-xs px-2.5 py-1 rounded-lg">
                Q {currentQuestion.seq_no}
              </span>
              <span className="text-xs font-bold text-slate-500">
                Level L{currentQuestion.level}
              </span>
            </div>
            <div className="flex items-center gap-3">
              <button
                onClick={toggleMute}
                className="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                title={isMuted ? 'Unmute TTS' : 'Mute TTS'}
              >
                {isMuted ? <VolumeX size={18} /> : <Volume2 size={18} />}
              </button>
              <span className="text-xs text-slate-405 font-mono">
                {Math.floor(timeTaken / 60)}:{(timeTaken % 60).toString().padStart(2, '0')}
              </span>
            </div>
          </div>

          {/* Interactive Card */}
          <div className="bg-white dark:bg-slate-900 border-2 border-indigo-100 dark:border-slate-800 rounded-3xl p-6 md:p-8 text-center min-h-[380px] flex flex-col items-center justify-between shadow-md relative overflow-hidden">
            {/* Status indicator */}
            <div className="absolute top-3 left-3 flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold text-indigo-600 dark:text-indigo-400">
              <span className={`w-2 h-2 rounded-full ${isListening ? 'bg-rose-500 animate-pulse' : (isSpeaking ? 'bg-indigo-500 animate-ping' : 'bg-slate-350')}`} />
              <span>{isListening ? 'Listening' : (isSpeaking ? 'AI Speaking' : 'Ready')}</span>
            </div>

            <div className="my-auto space-y-6 w-full">
              {/* State icon */}
              <div className={`text-6xl select-none transition-transform duration-300 ${isListening ? 'scale-110' : ''}`}>
                {isListening ? '🎙️' : (isSpeaking ? '🔊' : '👦')}
              </div>

              {/* Question Text */}
              <h2 className="text-lg md:text-xl font-bold text-slate-950 dark:text-slate-100 leading-relaxed px-4">
                {currentQuestion.prompt}
              </h2>

              {/* Live Transcript / Hearing Box */}
              {isListening && (
                <div className="w-full bg-rose-50/50 dark:bg-slate-950/60 border border-rose-100 dark:border-slate-800 rounded-2xl p-4 text-left animate-fade-in">
                  <span className="text-[10px] uppercase font-bold tracking-widest text-rose-650 dark:text-rose-400 block mb-1">
                    Hearing
                  </span>
                  <p className="text-sm text-slate-700 dark:text-slate-300 italic min-h-[1.5rem]">
                    {transcript || 'Child should speak now...'}
                  </p>
                </div>
              )}

              {/* Manual Typable fallback */}
              {showManualInput && (
                <div className="w-full space-y-3 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                  <label className="block text-xs text-left font-bold text-slate-400 uppercase">
                    Type Answer Fallback
                  </label>
                  <input
                    type="text"
                    value={manualInput}
                    onChange={(e) => setManualInput(e.target.value)}
                    placeholder="Type what the child answered..."
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-200 outline-none focus:border-indigo-500 text-sm"
                  />
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="w-full pt-6 flex flex-col sm:flex-row gap-3 items-center justify-center border-t border-slate-50 dark:border-slate-850">
              {!showManualInput ? (
                <>
                  <button
                    onClick={() => speakPrompt(currentQuestion.prompt)}
                    className="w-full sm:w-auto bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-xs py-3 px-5 rounded-xl transition"
                  >
                    🔊 Hear Question Again
                  </button>
                  <button
                    onClick={() => handleDoneSpeaking()}
                    disabled={!transcript}
                    className="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs py-3 px-6 rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5 justify-center"
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
                    Keyboard Input
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
                    className="w-full sm:w-auto bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-750 dark:text-slate-300 font-bold text-xs py-3 px-5 rounded-xl transition"
                  >
                    🎙️ Use Microphone
                  </button>
                  <button
                    onClick={() => handleDoneSpeaking()}
                    disabled={!manualInput.trim()}
                    className="w-full sm:w-auto bg-indigo-650 hover:bg-indigo-700 text-white font-bold text-xs py-3 px-6 rounded-xl transition disabled:opacity-40"
                  >
                    Submit Answer
                  </button>
                </>
              )}
            </div>
          </div>

          {/* End eval early */}
          <div className="text-center">
            <button
              onClick={handleCancel}
              className="text-xs text-rose-500 hover:text-rose-700 font-bold underline"
            >
              End Evaluation Early
            </button>
          </div>
        </div>
      )}

      {/* Evaluation Report view */}
      {screen === 'report' && report && (
        <div className="space-y-6 animate-fade-in">
          <div className="flex items-center justify-between gap-3">
            <h1 className="heading-fun text-2xl font-bold text-slate-900 dark:text-white">
              Speech Evaluation Report
            </h1>
            <Link
              href="/dashboard"
              className="bg-indigo-50 dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 text-indigo-700 dark:text-indigo-450 font-bold text-xs px-4 py-2 rounded-xl"
            >
              Dashboard
            </Link>
          </div>

          {/* Score Summary Box */}
          <div className="bg-gradient-to-br from-indigo-500 to-purple-650 text-white rounded-3xl p-6 md:p-8 shadow-lg flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div>
              <span className="text-[10px] tracking-wider uppercase font-bold opacity-80">Final Outcome</span>
              <h2 className="text-2xl font-extrabold mt-0.5">{report.final_level_name}</h2>
              <p className="text-xs opacity-90 mt-1">Settled on level L{report.final_level} across {report.questions_asked} adaptive evaluations</p>
            </div>
            <div className="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-center shrink-0 w-full md:w-32 border border-white/15">
              <span className="text-[10px] tracking-wider uppercase font-bold opacity-80 block">Accuracy</span>
              <span className="text-3xl font-black block mt-0.5">{report.final_pct}%</span>
            </div>
          </div>

          {/* Expert Markdown Analysis */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6 shadow-sm">
            <h3 className="font-extrabold text-slate-900 dark:text-white text-base mb-4 flex items-center gap-2">
              <Sparkles className="text-indigo-650" size={18} /> Clinical Diagnostics Report
            </h3>
            <div
              className="prose dark:prose-invert max-w-none text-sm leading-relaxed"
              dangerouslySetInnerHTML={{ __html: parseMarkdown(report.report_md) }}
            />
          </div>

          {/* Home Exercises */}
          <div className="bg-amber-50/50 dark:bg-slate-950/40 border border-amber-200/80 dark:border-amber-900/60 rounded-3xl p-6">
            <h3 className="font-bold text-amber-955 dark:text-amber-450 text-sm mb-3 flex items-center gap-1.5">
              <Clock size={16} /> Recommended Activity Today
            </h3>
            <div
              className="text-xs sm:text-sm leading-relaxed text-amber-900 dark:text-amber-400"
              dangerouslySetInnerHTML={{ __html: parseMarkdown(report.sample_exercise_md) }}
            />
          </div>

          {/* personalized therapist call request */}
          <div className="bg-gradient-to-r from-emerald-500 to-teal-650 text-white rounded-3xl p-6 shadow-md space-y-4">
            <h3 className="font-extrabold text-lg flex items-center gap-1.5">
              <CheckCircle2 size={20} /> Personalised Speech Therapy Plan
            </h3>
            <p className="text-xs sm:text-sm leading-relaxed opacity-95">
              Get an expert-curated 1-Week Home Speech Program custom-made for your child's level.
              Includes interactive learning cards, audio visual exercises, and two private clinician calls.
            </p>
            <div className="pt-2 flex items-center gap-4">
              <span className="text-2xl font-black">₹99</span>
              <span className="line-through text-xs opacity-75">₹299</span>
              <Link
                href="/wallet?need=99"
                className="ml-auto bg-white text-emerald-700 font-extrabold text-xs px-5 py-3 rounded-xl hover:scale-[1.02] transition shadow-md"
              >
                Purchase Plan
              </Link>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
