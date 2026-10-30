import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRef, useState } from "react";
import { Link, useParams, useSearchParams } from "react-router";
import { type ApiKey, type CreatedKey, type Project, api } from "./api";
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
  const projects = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") });
  const create = useMutation({
    mutationFn: (name: string) => api<Project>("/v1/projects", { method: "POST", body: { name } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects"] }),
  });

  return (
    <div className="space-y-5 p-6">
      <h1 className="text-lg font-semibold">Projects</h1>
      <NameForm label="Project name" placeholder="my-app" onSubmit={(n) => create.mutateAsync(n)} pending={create.isPending} error={create.error} />
      {projects.error && <Err error={projects.error} />}
      <div className="lh-panel overflow-x-auto">
        <table className="w-full text-left text-body">
          <thead className="border-b border-line text-label text-ink-muted">
            <tr>
              <th scope="col" className="px-3 py-2 font-medium">Name</th>
              <th scope="col" className="px-3 py-2 font-medium">Created</th>
            </tr>
          </thead>
          <tbody>
            {projects.data?.map((p) => (
              <tr key={p.id} className="h-row border-b border-line last:border-0">
                <td className="px-3">
                  <Link to={{ pathname: `/projects/${p.id}`, search: params.toString() }} className="font-medium text-accent hover:underline">
                    {p.name}
                  </Link>
                </td>
                <td className="px-3 text-ink-muted"><time>{date(p.created_at)}</time></td>
              </tr>
            ))}
            {projects.data?.length === 0 && (
              <tr><td colSpan={2} className="px-3 py-6 text-center text-ink-muted">No projects yet.</td></tr>
            )}
          </tbody>
        </table>
      </div>
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
    <div role="status" className="lh-panel space-y-2 border-accent-line bg-accent-tint p-4">
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
