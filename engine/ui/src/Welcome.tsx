import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router";
import { copyText } from "@/lib/clipboard";
import { type Snippet, type Stack, snippets } from "@/lib/onboarding";
import { cn } from "@/lib/utils";
import { type CreatedKey, type Project, api } from "./api";
import { Icon } from "./icons";

type FirstLog = { records: { severity: { text: string }; message: string; resource: Record<string, unknown> }[] };

const STEPS = ["Project", "Stack", "Install"];
const STACKS: { id: Stack; title: string; badge?: string; text: string; meta: string }[] = [
  {
    id: "symfony",
    title: "Symfony",
    badge: "Recommended",
    text: "The loghub/symfony-bundle ships Monolog logs in batches, correlates them with traces and can enable OpenTelemetry tracing.",
    meta: "PHP 8.4 · Symfony 8.1",
  },
  {
    id: "standalone",
    title: "Standalone",
    text: "Any language or tool. POST JSON batches to /api/v1/logs with the project key in the Authorization header.",
    meta: "curl · any HTTP client",
  },
];

function Stepper({ step }: { step: number }) {
  return (
    <ol className="flex items-center gap-3" aria-label="Setup progress">
      {STEPS.map((label, i) => {
        const done = i < step;
        const current = i === step;
        return (
          <li key={label} className="flex items-center gap-3" aria-current={current ? "step" : undefined}>
            {i > 0 && <span aria-hidden className="hidden h-px w-10 bg-border sm:block" />}
            <span className="flex items-center gap-2">
              <span
                className={cn(
                  "flex size-[22px] items-center justify-center rounded-full border text-micro font-semibold",
                  current ? "border-brand bg-brand text-brand-ink" : done ? "border-brand bg-brand-tint text-brand" : "border-border text-muted-foreground",
                )}
              >
                {done ? <Icon name="check" className="size-3" /> : i + 1}
              </span>
              <span className={cn("hidden text-body sm:inline", current ? "font-semibold" : "text-muted-foreground", done && "text-foreground")}>
                {label}
                {done && <span className="sr-only"> (done)</span>}
              </span>
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function Heading({ step, title, children }: { step: number; title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-2">
      <p className="text-micro font-semibold tracking-wide text-muted-foreground">STEP {step + 1} OF 3</p>
      <h1 className="text-[25px] font-bold tracking-tight">{title}</h1>
      <p className="text-sm leading-relaxed text-muted-foreground">{children}</p>
    </div>
  );
}

function CopyButton({ text, target, className, children }: { text: string; target: React.RefObject<HTMLElement | null>; className?: string; children?: React.ReactNode }) {
  const [copied, setCopied] = useState(false);
  return (
    <button type="button" className={className} onClick={() => copyText(text, target.current).then(setCopied)}>
      <Icon name={copied ? "check" : "copy"} className="size-3.5" />
      {copied ? "Copied" : (children ?? "Copy")}
    </button>
  );
}

function SnippetStep({ n, snippet }: { n: number; snippet: Snippet }) {
  const code = useRef<HTMLPreElement>(null);
  return (
    <li className="flex gap-3.5">
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-label font-semibold">{n}</span>
      <div className="min-w-0 flex-1 space-y-2">
        <h2 className="text-sm font-semibold">{snippet.title}</h2>
        <div className="overflow-hidden rounded-panel border border-line bg-sunken">
          <div className="flex h-[34px] items-center justify-between border-b border-line px-3">
            <span className="font-mono text-micro text-muted-foreground">{snippet.file}</span>
            <CopyButton text={snippet.code} target={code} className="lh-row-action text-muted-foreground hover:text-foreground" />
          </div>
          <pre ref={code} className="overflow-x-auto px-3.5 py-3 font-mono text-label leading-relaxed">{snippet.code}</pre>
        </div>
      </div>
    </li>
  );
}

/** First launch: create a project and its key, pick a stack, then wait for the first log. */
export function Welcome() {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [name, setName] = useState("");
  const [project, setProject] = useState<Project | null>(null);
  const [created, setCreated] = useState<CreatedKey | null>(null);
  const [choice, setChoice] = useState<Stack>("symfony");
  const [stack, setStack] = useState<Stack | null>(null);
  const [keyCopied, setKeyCopied] = useState(false);
  const [slow, setSlow] = useState(false);
  const keyRef = useRef<HTMLElement>(null);
  const leave = useRef<HTMLDialogElement>(null);
  const step = !created ? 0 : !stack ? 1 : 2;

  const create = useMutation({
    // A retry after a failed key request reuses the project instead of creating a second one.
    mutationFn: async (n: string) => {
      const p = project ?? (await api<Project>("/v1/projects", { method: "POST", body: { name: n } }));
      setProject(p);
      return api<CreatedKey>(`/v1/projects/${p.id}/keys`, { method: "POST", body: { label: "default" } });
    },
    onSuccess: (k) => {
      setCreated(k);
      qc.invalidateQueries({ queryKey: ["projects"] });
    },
  });

  const first = useQuery({
    queryKey: ["projects", project?.id, "first-log"],
    queryFn: () => api<FirstLog>(`/v1/projects/${project!.id}/logs/search`, { method: "POST", body: { limit: 1 } }),
    enabled: step === 2,
    refetchInterval: (q) => (q.state.data?.records.length ? false : 3000),
  });
  const log = first.data?.records[0];

  useEffect(() => {
    if (step !== 2) return;
    const t = setTimeout(() => setSlow(true), 120_000);
    return () => clearTimeout(t);
  }, [step]);

  const scoped = (path: string) => ({ pathname: path, search: project ? `project=${project.id}` : "" });
  const later = () => (keyCopied || log ? navigate(scoped("/")) : leave.current?.showModal());

  return (
    <div className="flex min-h-dvh flex-col bg-background">
      <header className="flex h-16 shrink-0 items-center justify-between gap-4 border-b border-border px-4 md:px-8">
        <span className="flex items-center gap-2.5 sm:w-56">
          <span className="flex size-7 items-center justify-center rounded-md bg-[#1D4ED8] text-sm font-bold text-white">L</span>
          <span className="text-sm font-bold">LogHub</span>
        </span>
        <Stepper step={step} />
        <span className="hidden w-56 sm:block" />
      </header>

      <main className={cn("mx-auto w-full flex-1 px-4 py-10 md:py-16", step === 0 ? "max-w-[520px]" : step === 1 ? "max-w-[640px]" : "max-w-[760px]")}>
        {step === 0 && (
          <form
            className="space-y-6"
            onSubmit={(e) => {
              e.preventDefault();
              if (name.trim()) create.mutate(name.trim());
            }}
          >
            <Heading step={0} title="Create your first project">
              A project groups the logs and traces of one application. LogHub creates its ingestion key right after.
            </Heading>
            {create.isError && (
              <div role="alert" className="flex gap-2.5 rounded-panel border border-error-tint bg-error-tint px-3.5 py-3 text-error-ink">
                <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
                <div className="text-body">
                  <p className="font-semibold">The project could not be created</p>
                  <p className="text-label leading-relaxed">{create.error.message}. Your input is kept; check that LogHub is running and try again.</p>
                </div>
              </div>
            )}
            <div className="lh-panel space-y-1.5 p-6">
              <label htmlFor="project-name" className="block text-body font-semibold">Project name</label>
              <input
                id="project-name"
                className="lh-input h-9"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Billing API"
                maxLength={255}
                autoFocus
                disabled={create.isPending || project !== null}
                aria-describedby="project-name-hint"
              />
              <p id="project-name-hint" className="text-label text-muted-foreground">Shown in the project switcher.</p>
            </div>
            <div className="flex justify-end">
              <button type="submit" className="lh-button lh-button-primary h-9 px-4" disabled={!name.trim() || create.isPending}>
                {create.isPending ? (
                  <>Creating project… <Icon name="loader" className="motion-safe:animate-spin" /></>
                ) : create.isError ? (
                  <>Try again <Icon name="rotate-ccw" /></>
                ) : (
                  <>Create project <Icon name="chevron-right" /></>
                )}
              </button>
            </div>
          </form>
        )}

        {step === 1 && (
          <form
            className="space-y-6"
            onSubmit={(e) => {
              e.preventDefault();
              setStack(choice);
            }}
          >
            <p role="status" className="flex items-center gap-2 rounded-panel border border-debug-tint bg-debug-tint px-3 py-2.5 text-body text-debug-ink">
              <Icon name="check" className="size-4" /> Project {project?.name} created. Its ingestion key is ready.
            </p>
            <Heading step={1} title={`How will ${project?.name} send its logs?`}>
              Pick the integration. It only changes the setup instructions on the next step; the key works with both.
            </Heading>
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="sr-only">Stack</legend>
              {STACKS.map((s) => (
                <label
                  key={s.id}
                  className={cn(
                    "flex cursor-pointer flex-col gap-3 rounded-panel border bg-surface p-[18px] transition-colors has-focus-visible:ring-2 has-focus-visible:ring-brand-line",
                    choice === s.id ? "border-brand bg-brand-tint ring-1 ring-brand" : "border-border hover:border-line-strong",
                  )}
                >
                  <span className="flex items-center justify-between">
                    <span className="flex items-center gap-2">
                      <span className="text-md font-semibold">{s.title}</span>
                      {s.badge && <span className="rounded-full bg-brand-tint px-2 py-0.5 text-micro font-semibold text-brand">{s.badge}</span>}
                    </span>
                    <input type="radio" name="stack" value={s.id} checked={choice === s.id} onChange={() => setChoice(s.id)} className="size-4 accent-brand" />
                  </span>
                  <span className="text-body leading-relaxed text-muted-foreground">{s.text}</span>
                  <span className="font-mono text-micro text-muted-foreground">{s.meta}</span>
                </label>
              ))}
            </fieldset>
            <div className="flex items-center justify-between gap-4">
              <p className="text-label text-muted-foreground">You can create more keys later in Projects.</p>
              <button type="submit" className="lh-button lh-button-primary h-9 px-4">
                Continue <Icon name="chevron-right" />
              </button>
            </div>
          </form>
        )}

        {step === 2 && created && stack && (
          <div className="space-y-6 pb-24">
            <Heading step={2} title={`Connect ${project?.name}`}>
              {stack === "symfony"
                ? "Copy the key, paste the snippets into your Symfony app, then trigger a request. This page detects the first log automatically."
                : "Copy the key, then POST a JSON batch to the ingestion endpoint from any language. This page detects the first log automatically."}
            </Heading>

            {slow && !log && (
              <div className="space-y-2.5 rounded-panel border border-warn-tint bg-warn-tint p-[18px] text-warn-ink">
                <p className="flex items-center gap-2 text-body font-semibold">
                  <Icon name="triangle-alert" className="size-4" /> Nothing yet? Check these first
                </p>
                <ul className="list-disc space-y-1 pl-6 text-label leading-relaxed">
                  <li>Getting 401 from the engine? Check LOGHUB_API_KEY{stack === "symfony" && ", then clear the Symfony cache"}.</li>
                  <li>LOGHUB_URL must be reachable from the app. In Docker, 127.0.0.1 is the container itself: use the engine service name.</li>
                  {stack === "symfony" && <li>Only records at level info and above are shipped by default (loghub.level).</li>}
                </ul>
              </div>
            )}

            <section className="lh-panel space-y-2.5 p-[18px]" aria-labelledby="key-title">
              <div className="flex items-center justify-between">
                <h2 id="key-title" className="text-body font-semibold">Ingestion key · {created.label}</h2>
                <span className="flex items-center gap-1.5 text-label font-semibold text-warn-ink">
                  <Icon name="eye-off" className="size-3.5" /> Shown only once
                </span>
              </div>
              <div className="flex gap-2">
                <code ref={keyRef} className="flex h-9 min-w-0 flex-1 items-center overflow-x-auto rounded-control border border-line bg-sunken px-3 font-mono text-label select-all">
                  {created.key}
                </code>
                <button
                  type="button"
                  className={cn("lh-button h-9", keyCopied ? "lh-button-quiet text-debug-ink" : "lh-button-primary")}
                  onClick={() => copyText(created.key, keyRef.current).then(setKeyCopied)}
                >
                  <Icon name={keyCopied ? "check" : "copy"} /> {keyCopied ? "Copied" : "Copy key"}
                </button>
              </div>
              <p className="text-label text-muted-foreground">LogHub only stores a hash. If you lose it, create a new key in Projects and revoke this one.</p>
            </section>

            <ol className="space-y-5">
              {snippets(stack, { url: window.location.origin, key: created.key, project: project?.name ?? "", now: new Date(created.created_at) }).map((s, i) => (
                <SnippetStep key={s.title} n={i + 1} snippet={s} />
              ))}
            </ol>
            <p className="text-label text-muted-foreground">
              {stack === "symfony"
                ? "Then load any page of the app: logs at level info and above are shipped at the end of the request."
                : "Expected answer: 202 {\"accepted\":1,\"rejected\":0}. A 401 means the key is wrong or revoked."}
            </p>
          </div>
        )}
      </main>

      {step === 2 && (
        <footer className={cn("sticky bottom-0 border-t px-4", log ? "border-debug-tint bg-debug-tint" : "border-border bg-surface")}>
          <div className="mx-auto flex min-h-[72px] max-w-[760px] flex-wrap items-center justify-between gap-3 py-3">
            <div role="status" className="flex items-center gap-2.5">
              <span className={cn("flex size-[18px] items-center justify-center rounded-full", log ? "bg-debug-dot/20" : slow ? "bg-warn-dot/20" : "bg-brand/15")}>
                <span className={cn("size-2 rounded-full", log ? "bg-debug-dot" : slow ? "bg-warn-dot" : "bg-brand motion-safe:animate-pulse")} />
              </span>
              <span>
                <span className="block text-body font-semibold">
                  {log ? `First log received from ${String(log.resource["service.name"])}` : slow ? "No log received yet" : `Waiting for the first log from ${project?.name}…`}
                </span>
                <span className={cn("block text-label text-muted-foreground", log && "font-mono")}>
                  {log ? `${log.severity.text} · ${log.message}` : first.isError ? `Could not check: ${first.error.message}` : "Checking every 3 s"}
                </span>
              </span>
            </div>
            <div className="flex items-center gap-2">
              <button type="button" className="lh-button text-muted-foreground hover:text-foreground" onClick={later}>
                {log ? "Go to Overview" : "I’ll do it later"}
              </button>
              <button type="button" className="lh-button lh-button-primary h-9 px-4" disabled={!log} onClick={() => navigate(scoped("/logs"))}>
                Open Logs <Icon name="chevron-right" />
              </button>
            </div>
          </div>
        </footer>
      )}

      <dialog ref={leave} aria-labelledby="leave-title" className="m-auto w-[min(440px,calc(100vw-2rem))] rounded-[10px] border border-border bg-surface p-6 shadow-lg backdrop:bg-black/60">
        <div className="space-y-4">
          <span className="flex size-9 items-center justify-center rounded-full bg-warn-tint text-warn-ink">
            <Icon name="key" className="size-[18px]" />
          </span>
          <div className="space-y-1.5">
            <h2 id="leave-title" className="text-md font-semibold">Leave without copying the key?</h2>
            <p className="text-body leading-relaxed text-muted-foreground">
              This key will not be shown again. {project?.name} stays created; you can generate a new key from Projects at any time.
            </p>
          </div>
          <form method="dialog" className="flex justify-end gap-2">
            <button type="button" className="lh-button lh-button-quiet h-9" onClick={() => navigate(scoped("/"))}>Leave anyway</button>
            <button
              className="lh-button lh-button-primary h-9"
              onClick={() => created && copyText(created.key, keyRef.current).then(setKeyCopied)}
            >
              <Icon name="copy" /> Copy key and stay
            </button>
          </form>
        </div>
      </dialog>
    </div>
  );
}
