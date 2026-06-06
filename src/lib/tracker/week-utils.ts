// Pure date/week helpers — usable in both server and client contexts

export function isoWeekKey(date: Date): string {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  const weekNo = Math.ceil((((d.getTime() - yearStart.getTime()) / 86400000) + 1) / 7);
  return `${d.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
}

export function mondayOfWeek(weekKey: string): Date {
  const [yearStr, wStr] = weekKey.split('-W');
  const year = Number(yearStr);
  const week = Number(wStr);
  const jan4 = new Date(year, 0, 4);
  const week1Mon = new Date(jan4);
  week1Mon.setDate(jan4.getDate() - ((jan4.getDay() + 6) % 7));
  const mon = new Date(week1Mon);
  mon.setDate(week1Mon.getDate() + (week - 1) * 7);
  return mon;
}

export function weekLabel(weekKey: string): string {
  const mon = mondayOfWeek(weekKey);
  const sun = new Date(mon);
  sun.setDate(mon.getDate() + 6);
  const fmt = (d: Date) => d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
  return `${fmt(mon)} – ${fmt(sun)}`;
}

// 0=Mon … 6=Sun
export function todayDayIdx(): number {
  const d = new Date().getDay(); // 0=Sun, 1=Mon ... 6=Sat
  return d === 0 ? 6 : d - 1;
}

export function addWeeks(weekKey: string, delta: number): string {
  const mon = mondayOfWeek(weekKey);
  mon.setDate(mon.getDate() + delta * 7);
  return isoWeekKey(mon);
}

export function isCurrentWeek(weekKey: string): boolean {
  return weekKey === isoWeekKey(new Date());
}
