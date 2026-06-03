'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useTranslation } from '@/components/I18nContext';
import {
  startSessionAction,
  getNextQuestionAction,
  submitAnswerAction,
  finaliseSessionAction
} from '@/app/actions/eval';
import {
  Sparkles,
  ClipboardList,
  Mic,
  Square,
  Check,
  AlertTriangle,
  ArrowRight,
  Loader2,
  Clock,
  Award,
  ChevronRight,
  Coins,
  Wallet
} from 'lucide-react';

interface EvalClientProps {
  childId: number;
  moduleKey: string;
  childName: string;
  price?: number;
  balance?: number;
  resuming?: boolean;
}

export default function EvalClient({ childId, moduleKey, childName, price = 0, balance = 0, resuming = false }: EvalClientProps) {
  const router = useRouter();
  const { language, t } = useTranslation();
  const [step, setStep] = useState<'intro' | 'loading' | 'question' | 'memory_preview' | 'report' | 'error'>('intro');
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [currentQuestion, setCurrentQuestion] = useState<any>(null);
  const [turnNo, setTurnNo] = useState(1);
  const [totalTurns, setTotalTurns] = useState(8);
  const [answerText, setAnswerText] = useState('');
  const [selectedChoice, setSelectedChoice] = useState('');
  const [isListening, setIsListening] = useState(false);
  const [memoryCountdown, setMemoryCountdown] = useState(0);
  const [report, setReport] = useState<any>(null);
  const [errorMsg, setErrorMsg] = useState('');
  const [needTopup, setNeedTopup] = useState<{ needed: number; balance: number } | null>(null);

  // Unified option detection: AI may send options under `options` or `choices`,
  // and type as `mcq`, `compare`, `feeling_match`, or `multiple_choice`.
  const questionOptions: string[] =
    (currentQuestion?.options && currentQuestion.options.length > 0
      ? currentQuestion.options
      : currentQuestion?.choices) || [];
  const isChoiceQuestion = questionOptions.length > 0;

  // Can the parent start? Free modules and resumes (already paid) are always OK.
  const canAfford = resuming || price <= 0 || balance >= price;

  // The AI sometimes copies the question into BOTH `prompt` and `stimulus`,
  // which renders the question twice. Hide the stimulus box when it just repeats
  // the prompt (whitespace/punctuation/case-insensitive).
  const normalize = (s: string) => (s || '').toLowerCase().replace(/[\s\p{P}]+/gu, '').trim();
  const stimulusDuplicatesPrompt =
    !!currentQuestion?.stimulus &&
    normalize(currentQuestion.stimulus) === normalize(currentQuestion.prompt);

  // Refs for tracking times and recognition
  const responseStartTs = useRef<number>(0);
  const recognitionRef = useRef<any>(null);
  // Text that existed before the current dictation began. onresult REBUILDS the
  // textarea from this + the full live transcript on every fire, so repeated /
  // cumulative result events can never stack the same words again.
  const baseTextRef = useRef<string>('');

  // Initialize SpeechRecognition if available
  useEffect(() => {
    if (typeof window !== 'undefined') {
      // Guard: don't create a second recognition instance (StrictMode double-invoke
      // / re-renders) — two live recognizers would each type the answer.
      if (recognitionRef.current) return;
      const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
      if (SpeechRecognition) {
        const rec = new SpeechRecognition();
        rec.continuous = false;
        rec.interimResults = false;

        rec.onstart = () => setIsListening(true);
        rec.onend = () => setIsListening(false);
        rec.onresult = (e: any) => {
          // Build the FULL transcript of this dictation from every result the API
          // currently holds, then REBUILD the textarea = base + transcript.
          // (Rebuilding, not appending, means repeated/cumulative onresult fires
          //  overwrite cleanly instead of stacking "Hi Hi this Hi this is…".)
          let transcript = '';
          for (let i = 0; i < e.results.length; i++) {
            transcript += e.results[i][0].transcript;
          }
          transcript = transcript.trim();
          const next = (baseTextRef.current + transcript).trim();
          setAnswerText(next);
        };
        rec.onerror = (err: any) => {
          console.error('Speech recognition error:', err);
          setIsListening(false);
        };

        recognitionRef.current = rec;
      }
    }
  }, []);

  // Handle Speech Recognition Language based on question content (EN or HI)
  const toggleListening = () => {
    if (!recognitionRef.current) {
      alert('Speech recognition is not supported on this browser. Please type your answer instead.');
      return;
    }

    if (isListening) {
      recognitionRef.current.stop();
    } else {
      // Snapshot whatever is already typed so dictation appends after it once,
      // then onresult rebuilds from this base (no re-stacking on repeat fires).
      baseTextRef.current = answerText ? answerText + ' ' : '';
      const isHindi = /[\u0900-\u097F]/.test(currentQuestion?.prompt || '');
      recognitionRef.current.lang = isHindi ? 'hi-IN' : 'en-IN';
      try { recognitionRef.current.start(); } catch { /* already started */ }
    }
  };

  // Start the evaluation session
  const startEval = async () => {
    setStep('loading');
    setErrorMsg('');

    try {
      const res = await startSessionAction(childId, moduleKey);
      if (res.ok && res.sessionId) {
        setSessionId(res.sessionId);
        await loadNextQuestion(res.sessionId);
      } else if (res.error === 'insufficient') {
        setNeedTopup({ needed: res.needed || 0, balance: res.balance || 0 });
        setStep('error');
        setErrorMsg(
          `This module needs ${res.needed} credits, but you have ${res.balance}. Please top up your wallet to continue.`
        );
      } else if (res.error === 'unavailable') {
        setStep('error');
        setErrorMsg(res.message || 'This module is currently unavailable.');
      } else if (res.error === 'charge_failed') {
        setStep('error');
        setErrorMsg(res.message || 'Could not deduct credits. Please try again.');
      } else {
        setStep('error');
        setErrorMsg('Failed to initialize assessment session.');
      }
    } catch (err: any) {
      setStep('error');
      setErrorMsg(err.message || 'An unexpected error occurred.');
    }
  };

  // Load the next question from engine
  const loadNextQuestion = async (sessId: number) => {
    setStep('loading');
    try {
      const res = await getNextQuestionAction(sessId, language);

      if (res.ok) {
        if (res.done) {
          // Finalize session and get report
          const finalRes = await finaliseSessionAction(sessId, language);
          if (finalRes.ok) {
            setReport(finalRes.report);
            setStep('report');
          } else {
            setStep('error');
            setErrorMsg(finalRes.error || 'Failed to generate report.');
          }
        } else {
          setCurrentQuestion(res.question);
          setTurnNo(res.turn_no);
          setTotalTurns(res.total);
          setAnswerText('');
          setSelectedChoice('');

          // Reset timer
          responseStartTs.current = Date.now();

          // Check if memory stimulus preview is needed
          if (res.question.memory_mode && res.question.stimulus) {
            setStep('memory_preview');
            setMemoryCountdown(res.question.display_seconds || 5);
          } else {
            setStep('question');
          }
        }
      } else {
        setStep('error');
        setErrorMsg(res.error || 'Failed to fetch the next task question.');
      }
    } catch (err: any) {
      setStep('error');
      setErrorMsg(err.message || 'Error occurred while loading next step.');
    }
  };

  // Countdown timer effect for memory stimulation preview
  useEffect(() => {
    if (step === 'memory_preview' && memoryCountdown > 0) {
      const timer = setTimeout(() => {
        setMemoryCountdown(memoryCountdown - 1);
      }, 1000);
      return () => clearTimeout(timer);
    } else if (step === 'memory_preview' && memoryCountdown === 0) {
      setStep('question');
      responseStartTs.current = Date.now(); // Reset question timing post-preview
    }
  }, [step, memoryCountdown]);

  // Submit current answer to server
  const submitAnswer = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!sessionId) return;

    setStep('loading');
    const responseSeconds = Math.round((Date.now() - responseStartTs.current) / 1000);

    const payload: any = {
      response_seconds: responseSeconds,
    };

    if (isChoiceQuestion) {
      if (!selectedChoice) {
        alert(t('eval.pickOption'));
        setStep('question');
        return;
      }
      payload.choice = selectedChoice;
    } else {
      if (!answerText.trim()) {
        alert(t('eval.enterAnswer'));
        setStep('question');
        return;
      }
      payload.text = answerText;
    }

    try {
      const res = await submitAnswerAction(sessionId, payload, language);
      if (res.ok) {
        await loadNextQuestion(sessionId);
      } else {
        setStep('error');
        setErrorMsg(res.error || 'Failed to submit answer.');
      }
    } catch (err: any) {
      setStep('error');
      setErrorMsg(err.message || 'Verification error occurred.');
    }
  };

  return (
    <div className="max-w-2xl mx-auto pt-20 pb-8 px-4 space-y-6">
      {/* Intro Screen */}
      {step === 'intro' && (
        <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 sm:p-8 space-y-6 animate-fade-in">
          <div className="text-center space-y-2 flex flex-col items-center">
            <Sparkles className="text-indigo-600 mb-2" size={48} />
            <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100">
              {t('eval.ready')}
            </h1>
            <p className="text-sm text-slate-500 max-w-sm mx-auto">
              {t('eval.aboutA')} <strong className="text-slate-700 dark:text-slate-350">{moduleKey}</strong> {t('eval.aboutB')} <strong className="text-slate-700 dark:text-slate-350">{childName}</strong>.
            </p>
          </div>

          {/* Cost & balance — shown BEFORE starting so the parent knows the charge */}
          <div className={`rounded-2xl p-4 flex items-center justify-between gap-3 border ${
            canAfford
              ? 'bg-indigo-50 dark:bg-slate-800/50 border-indigo-100 dark:border-slate-800'
              : 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-900'
          }`}>
            <div className="flex items-center gap-2.5">
              <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${canAfford ? 'bg-indigo-100 dark:bg-indigo-900/40' : 'bg-amber-100 dark:bg-amber-900/40'}`}>
                <Coins size={18} className={canAfford ? 'text-indigo-600' : 'text-amber-600'} />
              </div>
              <div className="text-left">
                <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{t('eval.cost')}</div>
                <div className="text-lg font-extrabold text-slate-800 dark:text-slate-100 leading-tight">
                  {price > 0 ? `${price} ${t('eval.credits')}` : t('eval.free')}
                </div>
              </div>
            </div>
            <div className="text-right">
              <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{t('eval.balance')}</div>
              <div className={`text-lg font-extrabold leading-tight ${canAfford ? 'text-slate-800 dark:text-slate-100' : 'text-amber-700 dark:text-amber-400'}`}>
                {balance} {t('nav.cr')}
              </div>
            </div>
          </div>

          {resuming ? (
            <p className="text-xs text-center text-emerald-600 -mt-2 font-semibold">
              {t('eval.resumeNote')}
            </p>
          ) : price > 0 ? (
            <p className="text-xs text-center text-slate-400 -mt-2">
              {canAfford
                ? `${price} ${t('eval.willDeductA')} ${balance - price} ${t('nav.cr')}.`
                : `${t('eval.needMoreA')} ${price - balance} ${t('eval.needMoreB')}`}
            </p>
          ) : null}

          <div className="bg-indigo-50 dark:bg-slate-800/50 border border-indigo-100 dark:border-slate-850 rounded-2xl p-4 text-xs sm:text-sm text-indigo-900 dark:text-slate-300 space-y-2">
            <p className="font-bold flex items-center gap-1.5 text-indigo-750 dark:text-indigo-400">
              <ClipboardList size={16} /> {t('eval.instructions')}
            </p>
            <ul className="list-disc list-inside space-y-1 text-slate-500 dark:text-slate-400">
              <li>{t('eval.inst1')}</li>
              <li>{t('eval.inst2')}</li>
              <li>{t('eval.inst3')}</li>
              <li>{t('eval.inst4')}</li>
            </ul>
          </div>

          <div className="flex gap-4">
            <Link
              href="/dashboard"
              className="flex-1 text-center border-2 border-slate-200 dark:border-slate-800 text-slate-500 py-3 rounded-xl hover:bg-slate-50 font-bold transition-all text-sm flex items-center justify-center"
            >
              {t('eval.cancel')}
            </Link>
            {canAfford ? (
              <button
                onClick={startEval}
                className="flex-1 btn-premium btn-premium-primary py-3 rounded-xl font-bold hover:shadow-lg transition-all text-sm"
              >
                {resuming ? t('eval.resume') : price > 0 ? `${t('eval.startShort')} · ${price} ${t('nav.cr')}` : t('eval.start')}
              </button>
            ) : (
              <Link
                href="/wallet"
                className="flex-1 text-center btn-premium btn-premium-primary py-3 rounded-xl font-bold hover:shadow-lg transition-all text-sm flex items-center justify-center gap-1.5"
              >
                <Wallet size={16} /> {t('eval.topup')}
              </Link>
            )}
          </div>
        </div>
      )}

      {/* Loading State */}
      {step === 'loading' && (
        <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-12 text-center flex flex-col items-center justify-center space-y-4 min-h-[300px]">
          <Loader2 className="w-12 h-12 text-indigo-600 animate-spin" />
          <p className="text-sm text-slate-500 animate-pulse">{t('eval.loading')}</p>
        </div>
      )}

      {/* Memory Mode Preview Screen */}
      {step === 'memory_preview' && (
        <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-8 text-center flex flex-col items-center justify-center space-y-6 min-h-[350px] animate-fade-in">
          <span className="text-xs font-bold text-indigo-650 uppercase tracking-widest flex items-center gap-1">
            <Clock size={12} /> {t('eval.memorise')}
          </span>
          <div className="text-4xl sm:text-5xl font-extrabold text-slate-800 dark:text-slate-100 tracking-wide bg-slate-50 dark:bg-slate-800 px-8 py-5 rounded-2xl border border-slate-100 dark:border-slate-700 select-none">
            {currentQuestion?.stimulus}
          </div>
          <div className="space-y-1">
            <p className="text-sm text-slate-500 font-semibold">{t('eval.hiding')}</p>
            <div className="text-3xl font-extrabold text-indigo-600">{memoryCountdown}s</div>
          </div>
          <div className="w-full max-w-xs h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
            <div
              className="h-full bg-indigo-600 transition-all duration-1000"
              style={{ width: `${(memoryCountdown / (currentQuestion?.display_seconds || 5)) * 100}%` }}
            ></div>
          </div>
        </div>
      )}

      {/* Question Screen */}
      {step === 'question' && currentQuestion && (
        <div className="space-y-4 animate-fade-in">
          {/* Header Progress */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex justify-between items-center text-xs">
            <span className="font-bold text-slate-700 dark:text-slate-300">{childName}{t('eval.evaluation')}</span>
            <span className="text-slate-400 font-semibold">
              {t('eval.question')} {turnNo} {t('eval.of')} {totalTurns}
            </span>
          </div>

          {/* Main Card */}
          <div className="card-premium bg-white dark:bg-slate-900 border border-indigo-100 dark:border-slate-800 p-6 sm:p-8 min-h-[350px] flex flex-col justify-between">
            <div className="space-y-6">
              <h2 className="heading-fun text-xl sm:text-2xl font-bold text-slate-800 dark:text-slate-100 text-center leading-relaxed">
                {currentQuestion.prompt}
              </h2>

              {currentQuestion.stimulus && !currentQuestion.memory_mode && !stimulusDuplicatesPrompt && (
                <div className="text-center py-4">
                  <span className="inline-block text-3xl font-bold bg-slate-50 dark:bg-slate-800 px-6 py-3 rounded-xl border border-slate-100">
                    {currentQuestion.stimulus}
                  </span>
                </div>
              )}

              {/* Form Input */}
              <form onSubmit={submitAnswer} className="space-y-4">
                {isChoiceQuestion ? (
                  <div className="grid grid-cols-1 gap-3">
                    {questionOptions.map((ch: string) => {
                      const selected = selectedChoice === ch;
                      return (
                        <button
                          key={ch}
                          type="button"
                          onClick={() => setSelectedChoice(ch)}
                          className={`text-left p-4 rounded-xl border-2 font-bold text-sm sm:text-base transition-all cursor-pointer ${
                            selected
                              ? 'bg-indigo-600 border-indigo-600 text-white shadow-md'
                              : 'bg-slate-50 dark:bg-slate-800/50 border-slate-100 dark:border-slate-700 text-slate-700 dark:text-slate-350 hover:bg-slate-100'
                          }`}
                        >
                          {ch}
                        </button>
                      );
                    })}
                  </div>
                ) : (
                  <div className="space-y-4">
                    <textarea
                      rows={3}
                      value={answerText}
                      onChange={(e) => setAnswerText(e.target.value)}
                      placeholder={t('eval.typeAnswer')}
                      className="input-premium py-3 resize-none text-base"
                      required
                    ></textarea>

                    {recognitionRef.current && (
                      <div className="flex justify-center">
                        <button
                          type="button"
                          onClick={toggleListening}
                          className={`flex items-center gap-2 px-5 py-3 rounded-full font-bold text-sm transition-all shadow-md cursor-pointer border-none ${
                            isListening
                              ? 'bg-rose-600 text-white animate-pulse'
                              : 'bg-indigo-50 text-indigo-750 hover:bg-indigo-100'
                          }`}
                        >
                          {isListening ? (
                            <>
                              <Square size={16} />
                              <span>{t('eval.stop')}</span>
                            </>
                          ) : (
                            <>
                              <Mic size={16} />
                              <span>{t('eval.speak')}</span>
                            </>
                          )}
                        </button>
                      </div>
                    )}
                  </div>
                )}

                <button
                  type="submit"
                  className="w-full btn-premium btn-premium-primary py-3.5 mt-6 text-sm font-bold shadow-lg flex items-center justify-center gap-1.5"
                >
                  <span>{t('eval.submit')}</span>
                  <ChevronRight size={16} />
                </button>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Report Screen */}
      {step === 'report' && report && (
        <div className="space-y-6 animate-fade-in">
          <div className="flex justify-between items-baseline">
            <h1 className="heading-fun text-3xl font-extrabold text-slate-800 dark:text-slate-100">
              {t('eval.completed')}
            </h1>
            <Link href="/dashboard" className="text-sm text-indigo-650 hover:underline">
              {t('nav.dashboard')}
            </Link>
          </div>

          {/* Core Score Banner */}
          <div className="bg-indigo-600 rounded-3xl p-6 sm:p-8 text-white shadow-xl text-center space-y-4">
            <p className="text-xs uppercase font-bold tracking-widest opacity-90 flex items-center justify-center gap-1.5">
              <Award size={16} /> {t('eval.overall')}
            </p>
            <div className="text-6xl sm:text-7xl font-extrabold">{report.overall_score || 0}</div>
            <div>
              <span className="bg-white/20 px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider">
                {t('eval.level')}: {report.level || 'Developing'}
              </span>
            </div>
          </div>

          {/* Details Summary */}
          <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 sm:p-8 space-y-4 shadow-sm">
            <h3 className="heading-fun text-xl font-bold text-slate-800 dark:text-slate-100">
              {t('eval.aiSummary')}
            </h3>
            <p className="text-sm text-slate-500 leading-relaxed whitespace-pre-wrap">
              {report.summary}
            </p>

            {report.recommended_focus && (
              <div className="border-t border-slate-100 dark:border-slate-800 pt-4">
                <span className="text-[10px] font-bold text-indigo-600 uppercase tracking-wider block mb-1">
                  {t('eval.focusRec')}
                </span>
                <p className="text-sm font-bold text-slate-700 dark:text-slate-300">
                  {report.recommended_focus}
                </p>
              </div>
            )}
          </div>

          {/* Strengths */}
          {report.strengths && report.strengths.length > 0 && (
            <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-6 sm:p-8 shadow-sm">
              <h3 className="font-bold text-slate-800 dark:text-slate-100 text-lg mb-3">{t('eval.strengths')}</h3>
              <ul className="space-y-2">
                {report.strengths.map((str: string, index: number) => (
                  <li key={index} className="text-sm text-slate-500 flex items-start gap-2">
                    <Check className="text-indigo-600 mt-0.5" size={16} />
                    <span>{str}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <Link href="/dashboard" className="w-full btn-premium btn-premium-primary text-center py-3.5 block shadow-md font-bold">
            {t('eval.done')}
          </Link>
        </div>
      )}

      {/* Error Screen */}
      {step === 'error' && (
        <div className="card-premium bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 p-8 text-center space-y-4 animate-fade-in flex flex-col items-center">
          <AlertTriangle className={`mb-2 ${needTopup ? 'text-amber-500' : 'text-rose-600'}`} size={48} />
          <h2 className={`heading-fun text-xl font-bold ${needTopup ? 'text-amber-700 dark:text-amber-400' : 'text-rose-800 dark:text-rose-400'}`}>
            {needTopup ? t('eval.notEnough') : t('eval.errTitle')}
          </h2>
          <p className="text-slate-500 text-sm max-w-sm mx-auto leading-relaxed">{errorMsg}</p>
          <div className="flex flex-col sm:flex-row gap-3 items-center">
            {needTopup && (
              <Link
                href="/wallet"
                className="btn-premium btn-premium-primary px-6 py-2.5 text-xs font-bold cursor-pointer rounded-xl"
              >
                {t('eval.topupWallet')}
              </Link>
            )}
            <button
              onClick={() => { setNeedTopup(null); setStep('intro'); }}
              className="btn-premium btn-premium-secondary px-6 py-2.5 text-xs font-bold cursor-pointer"
            >
              {needTopup ? t('eval.goBack') : t('eval.tryAgain')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}