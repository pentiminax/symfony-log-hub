import { useMutation, useQueries, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRef, useState } from "react";
import { Link, useParams, useSearchParams } from "react-router";
import { type ApiKey, type CreatedKey, type Project, api } from "./api";
import { MetricCard } from "@/components/MetricCard";
import { StateView } from "@/components/StateView";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "./icons";

const date = (iso: string) => new Date(iso).toLocaleString();

function Err({ error }: { error: unknown }) {
  return (
    <p role="alert" className="flex items-start gap-2 rounded-control bg-error-tint px-2.5 py-2 text-body text-error-ink">
      <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
      <span>{error instanceof Error ? error.message : "Something went wrong."}</span>
    </p>
  );
}

function NameForm({
  label,
  placeholder,
  onSubmit,
  pending,
  error,
}: {
  label: string;
  placeholder: string;
  onSubmit: (value: string) => Promise<unknown>;
  pending: boolean;
  error: unknown;
}) {
  const [value, setValue] = useState("");
  const id = `new-${label.toLowerCase().replace(/\W+/g, "-")}`;
  return (
    <form
      className="flex items-end gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        // Keep the input on failure so the user can fix it; the error is shown via `error`.
        onSubmit(value.trim()).then(() => setValue(""), () => {});
      }}
    >
      <div className="max-w-xs flex-1">
        <label htmlFor={id} className="mb-1.5 block text-label font-medium text-ink-muted">
          {label}
        </label>
        <input id={id} className="lh-input" value={value} onChange={(e) => setValue(e.target.value)} placeholder={placeholder} required maxLength={255} />
      </div>
      <button type="submit" disabled={pending || !value.trim()} className="lh-button lh-button-primary">
        <Icon name="plus" /> Create
      </button>
      {error != null && <Err error={error} />}
    </form>
  );
}

export function ProjectsList() {
  const qc = useQueryClient();
  const [params] = useSearchParams();
  const [filter, setFilter] = useState("");
  const projects = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") });
  const create = useMutation({
    mutationFn: (name: string) => api<Project>("/v1/projects", { method: "POST", body: { name } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects"] }),
  });
  // One small request per project; the same cache keys as the detail page, so opening one is instant.
  const keys = useQueries({
    queries: (projects.data ?? []).map((p) => ({ queryKey: ["projects", p.id, "keys"], queryFn: () => api<ApiKey[]>(`/v1/projects/${p.id}/keys`) })),
  });
  const active = (i: number) => keys[i]?.data?.filter((k) => !k.revoked_at).length;
  const activeTotal = keys.every((k) => k.data) ? keys.reduce((n, k) => n + (k.data?.filter((x) => !x.revoked_at).length ?? 0), 0) : undefined;
  const shown = (projects.data ?? []).map((p, i) => ({ p, i })).filter(({ p }) => p.name.toLowerCase().includes(filter.trim().toLowerCase()));
  const scoped = (path: string, id: string) => ({ pathname: path, search: new URLSearchParams({ project: id }).toString() });

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div>
        <h1 className="text-lg font-semibold tracking-tight">Projects</h1>
        <p className="text-body text-muted-foreground">Create and manage your projects and their API keys.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:max-w-md">
        <MetricCard label="Projects" value={projects.data?.length} loading={projects.isPending} />
        <MetricCard label="Active API keys" value={activeTotal} loading={activeTotal === undefined && !projects.isError} />
      </div>

      <NameForm label="New project" placeholder="my-app" onSubmit={(n) => create.mutateAsync(n)} pending={create.isPending} error={create.error} />

      <Card className="gap-0 p-0">
        <div className="flex items-center gap-3 px-4 py-3">
          <h2 className="mr-auto whitespace-nowrap text-md font-medium">Your projects</h2>
          <label className="sr-only" htmlFor="project-filter">Search projects</label>
          <input id="project-filter" type="search" className="lh-input max-w-60" placeholder="Search projects…" value={filter} onChange={(e) => setFilter(e.target.value)} />
        </div>
        {projects.isPending ? (
          <StateView kind="loading" title="Loading projects" />
        ) : projects.isError ? (
          <StateView kind="error" action={{ label: "Try again", onClick: () => projects.refetch() }}>{projects.error.message}</StateView>
        ) : projects.data.length === 0 ? (
          <StateView kind="empty" icon="projects" title="No projects yet">Create your first project above to start receiving logs.</StateView>
        ) : shown.length === 0 ? (
          <StateView kind="empty" icon="search" title="No project found" action={{ label: "Clear search", onClick: () => setFilter("") }}>
            Nothing matches “{filter}”.
          </StateView>
        ) : (
          <ul className="divide-y divide-border border-t border-border">
            {shown.map(({ p, i }) => (
              <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <div className="min-w-0 flex-1 basis-48">
                  <Link to={{ pathname: `/projects/${p.id}`, search: params.toString() }} className="font-medium text-brand hover:underline">{p.name}</Link>
                  <p className="text-label text-muted-foreground">Created <time dateTime={p.created_at}>{date(p.created_at)}</time></p>
                </div>
                <span className="tnum text-label text-muted-foreground">{active(i) ?? "…"} active key{active(i) === 1 ? "" : "s"}</span>
                <span className="flex gap-1">
                  <Button variant="outline" size="sm" render={<Link to={scoped("/logs", p.id)} />}>Logs</Button>
                  <Button variant="outline" size="sm" render={<Link to={scoped("/traces", p.id)} />}>Traces</Button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function NewKey({ created, onDismiss }: { created: CreatedKey; onDismiss: () => void }) {
  const [copied, setCopied] = useState(false);
  const code = useRef<HTMLElement>(null);
  // navigator.clipboard only exists in secure contexts (HTTPS or localhost): over plain HTTP
  // on a LAN address, select the key so the user can copy it by hand.
  const copy = () => {
    const select = () => code.current && window.getSelection()?.selectAllChildren(code.current);
    if (!navigator.clipboard) return select();
    navigator.clipboard.writeText(created.key).then(() => setCopied(true), select);
  };
  return (
    <div role="status" className="lh-panel space-y-2 border-brand-line bg-brand-tint p-4">
      <p className="text-body font-medium">
        Key “{created.label}” created. Copy it now: you won’t see it again.
      </p>
      <div className="flex items-center gap-2">
        <code ref={code} className="select-all min-w-0 flex-1 overflow-x-auto rounded-control bg-surface px-2.5 py-1.5 font-mono text-label">{created.key}</code>
        <button
          type="button"
          className="lh-button lh-button-quiet"
          onClick={copy}
        >
          <Icon name="copy" /> {copied ? "Copied" : "Copy"}
        </button>
        <button type="button" className="lh-button lh-button-quiet" onClick={onDismiss}>Done</button>
      </div>
    </div>
  );
}

export function ProjectDetail() {
  const { id = "" } = useParams();
  const qc = useQueryClient();
  const [created, setCreated] = useState<CreatedKey | null>(null);
  const project = useQuery({ queryKey: ["projects", id], queryFn: () => api<Project>(`/v1/projects/${id}`) });
  const keys = useQuery({ queryKey: ["projects", id, "keys"], queryFn: () => api<ApiKey[]>(`/v1/projects/${id}/keys`) });
  const refresh = () => qc.invalidateQueries({ queryKey: ["projects", id, "keys"] });
  const create = useMutation({
    mutationFn: (label: string) => api<CreatedKey>(`/v1/projects/${id}/keys`, { method: "POST", body: { label } }),
    onSuccess: (k) => {
      setCreated(k);
      refresh();
    },
  });
  const revoke = useMutation({
    mutationFn: (keyId: string) => api<ApiKey>(`/v1/projects/${id}/keys/${keyId}/revoke`, { method: "POST" }),
    onSuccess: refresh,
  });

  return (
    <div className="space-y-5 p-6">
      <div>
        <Link to="/projects" className="text-label text-ink-muted hover:text-ink">← Projects</Link>
        <h1 className="text-lg font-semibold">{project.data?.name ?? "Project"}</h1>
        {project.error && <Err error={project.error} />}
      </div>

      {created && <NewKey created={created} onDismiss={() => setCreated(null)} />}
      <NameForm label="New key label" placeholder="production" onSubmit={(l) => create.mutateAsync(l)} pending={create.isPending} error={create.error} />
      {keys.error && <Err error={keys.error} />}
      {revoke.error && <Err error={revoke.error} />}

      <div className="lh-panel overflow-x-auto">
        <table className="w-full text-left text-body">
          <thead className="border-b border-line text-label text-ink-muted">
            <tr>
              <th scope="col" className="px-3 py-2 font-medium">Label</th>
              <th scope="col" className="px-3 py-2 font-medium">Created</th>
              <th scope="col" className="px-3 py-2 font-medium">Revoked</th>
              <th scope="col" className="px-3 py-2"><span className="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            {keys.data?.map((k) => (
              <tr key={k.id} className="h-row border-b border-line last:border-0">
                <td className="px-3 font-medium">{k.label}</td>
                <td className="px-3 text-ink-muted"><time>{date(k.created_at)}</time></td>
                <td className="px-3 text-ink-muted">{k.revoked_at ? <time>{date(k.revoked_at)}</time> : "—"}</td>
                <td className="px-3 text-right">
                  {!k.revoked_at && (
                    <button
                      type="button"
                      className="lh-button lh-button-quiet"
                      disabled={revoke.isPending}
                      onClick={() => confirm(`Revoke key “${k.label}”? Clients using it will be rejected.`) && revoke.mutate(k.id)}
                    >
                      Revoke
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {keys.data?.length === 0 && (
              <tr><td colSpan={4} className="px-3 py-6 text-center text-ink-muted">No keys yet.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
