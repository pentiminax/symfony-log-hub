const KEY = "loghub.masterKey";

export const getKey = (): string | null => {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
};
export const setKey = (key: string) => localStorage.setItem(KEY, key);
export const clearKey = () => {
  try {
    localStorage.removeItem(KEY);
  } catch {
    /* storage unavailable */
  }
  window.dispatchEvent(new Event("loghub:logout"));
};

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message);
  }
}

export async function api<T>(
  path: string,
  init: { method?: string; body?: unknown; key?: string } = {},
): Promise<T> {
  const key = init.key ?? getKey();
  const res = await fetch(path, {
    method: init.method ?? "GET",
    headers: {
      ...(key ? { Authorization: `Bearer ${key}` } : {}),
      ...(init.body !== undefined ? { "Content-Type": "application/json" } : {}),
    },
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
  // A rejected stored key means "log in again"; a rejected key on the login form is just an error.
  if (res.status === 401 && init.key === undefined) clearKey();
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new ApiError(res.status, body.message ?? body.error ?? `HTTP ${res.status}`);
  }
  return res.json() as Promise<T>;
}

export type Project = { id: string; name: string; created_at: string };
export type ApiKey = {
  id: string;
  project_id: string;
  label: string;
  created_at: string;
  revoked_at: string | null;
};
export type CreatedKey = ApiKey & { key: string };
