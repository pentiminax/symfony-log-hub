export const LEVELS = [
  ["trace", [1, 4]],
  ["debug", [5, 8]],
  ["info", [9, 12]],
  ["warn", [13, 16]],
  ["error", [17, 20]],
  ["fatal", [21, 24]],
] as const;

export const levelOf = (n: number) => LEVELS[n <= 4 ? 0 : n <= 8 ? 1 : n <= 12 ? 2 : n <= 16 ? 3 : n <= 20 ? 4 : 5][0];
export const cap = (s: string) => s[0].toUpperCase() + s.slice(1);

/** Dot + word: the colour is never the only signal. */
export function Severity({ n }: { n: number }) {
  const l = levelOf(n);
  return (
    <span className={`lh-severity lh-severity-${l}`}>
      <span className={`lh-severity-dot lh-severity-dot-${l}`} />
      {cap(l)}
    </span>
  );
}
