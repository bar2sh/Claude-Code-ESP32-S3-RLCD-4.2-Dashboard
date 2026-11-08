// What the Dashboard shows about plan limits and sessions, as plain data (docs/SPEC.md, "Dashboard").
// No page and no storage here, so the Node tests run it as it is. The rules are fail-closed: a number
// that cannot be trusted is not shown, and every reading says how old it is.

/** Percent from which a limit bar turns to a warning. */
export const WARN_PCT = 80;

const whole = (v) => Number.isFinite(v) && v >= 0;

/**
 * One limit window from the server's `usage.five` / `usage.week` ({p, left, age, expired}, all as of the fetch).
 * `elapsed`: seconds since the fetch. Returns
 *   {kind: 'none'}                              no reading (or one that makes no sense);
 *   {kind: 'renewed', age}                      the window has reset since: no percentage;
 *   {kind: 'bar', pct, level, left, age}        level 'ok' | 'warn' | 'full'; left and age in seconds.
 */
export function limitBar(reading, elapsed = 0) {
  if (!reading || typeof reading !== 'object') return { kind: 'none' };
  const { p, left, age } = reading;
  if (!Number.isFinite(p) || p < 0 || p > 100 || !whole(left) || !whole(age)) return { kind: 'none' };
  const gone = Math.max(0, elapsed);
  if (reading.expired === true || left - gone <= 0) return { kind: 'renewed', age: age + gone };
  const pct = Math.round(p);
  return {
    kind: 'bar',
    pct,
    level: pct >= 100 ? 'full' : pct >= WARN_PCT ? 'warn' : 'ok',
    left: left - gone,
    age: age + gone,
  };
}

/** Seconds as [unit, n] pairs for the app to word: ['d', 2, 'h', 5], ['h', 1, 'min', 20] or ['min', 7]. */
export function span(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const min = Math.floor(s / 60);
  if (s < 60) return [];
  if (min < 60) return ['min', min];
  const h = Math.floor(min / 60);
  if (h < 24) return ['h', h, 'min', min % 60];
  return ['d', Math.floor(h / 24), 'h', h % 24];
}

/**
 * The facts of a session row that the hook sent, in the order they are shown; each is
 * {id, n?, d?, t?} and the app words it. A fact the hook never sent is left out (no "0/0", no guess).
 * `elapsed`: seconds since the fetch, for the turn clock.
 */
export function sessionFacts(s, elapsed = 0) {
  const out = [];
  const count = (v) => Number.isInteger(v) && v >= 0;
  if (count(s.turns) && s.turns > 0) out.push({ id: 'turn', n: s.turns });
  if (s.state === 'working' && whole(s.turn_age)) out.push({ id: 'turnTime', seconds: s.turn_age + Math.max(0, elapsed) });
  if (count(s.agents) && s.agents > 0) out.push({ id: 'agents', n: s.agents });
  if (Number.isInteger(s.ctx) && s.ctx >= 0 && s.ctx <= 100) out.push({ id: 'ctx', n: s.ctx });
  if (typeof s.model === 'string' && /^[A-Za-z0-9 ._-]{1,32}$/.test(s.model)) out.push({ id: 'model', text: s.model });
  return out;
}

/** Task progress {d, t} or null: only a believable "done of total" (1 <= total, 0 <= done <= total). */
export function taskProgress(s) {
  const t = s?.tasks;
  if (!t || !Number.isInteger(t.d) || !Number.isInteger(t.t) || t.t < 1 || t.d < 0 || t.d > t.t) return null;
  return { d: t.d, t: t.t, pct: Math.round((t.d / t.t) * 100) };
}

export const KINDS = ['question', 'plan', 'permission'];
/** What a waiting session waits for, or null (an unknown kind from a newer server is not guessed). */
export const waitKind = (s) => (s.state === 'waiting' && KINDS.includes(s.kind) ? s.kind : null);
