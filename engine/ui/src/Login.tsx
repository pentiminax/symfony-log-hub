import { useState } from "react";
import { api, setKey as storeKey } from "./api";
import { Icon } from "./icons";

export function Login({ onLogin }: { onLogin: (key: string) => void }) {
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      // Cheapest master-key endpoint: validates the key without storing it first.
      await api("/v1/projects", { key: value });
      storeKey(value);
      onLogin(value);
    } catch (err) {
      setError(
        (err as { status?: number }).status === 401
          ? "Invalid master key."
          : "Could not reach the LogHub engine.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
      <div className="w-full max-w-[22rem]">
        <div className="mb-6 flex items-center gap-2.5">
          <span className="flex size-8 items-center justify-center rounded-md bg-rail text-rail-ink-strong">
            <Icon name="logs" className="size-[18px]" />
          </span>
          <span className="text-xl font-semibold tracking-tight">LogHub</span>
        </div>

        <form onSubmit={submit} className="lh-panel p-5">
          <h1 className="text-md font-semibold">Sign in</h1>
          <p className="mt-1 text-body text-ink-muted">Enter the master key of this LogHub instance.</p>

          {error && (
            <p role="alert" className="mt-4 flex items-start gap-2 rounded-control bg-error-tint px-2.5 py-2 text-body text-error-ink">
              <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
              <span>{error}</span>
            </p>
          )}

          <div className="mt-5">
            <label htmlFor="master-key" className="mb-1.5 block text-label font-medium text-ink-muted">
              Master key
            </label>
            <input
              id="master-key"
              type="password"
              className="lh-input"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              required
              autoFocus
              autoComplete="current-password"
              spellCheck={false}
            />
          </div>

          <button type="submit" disabled={busy} className="lh-button lh-button-primary mt-5 w-full">
            {busy ? "Checking…" : "Sign in"}
          </button>
        </form>
      </div>
    </main>
  );
}
