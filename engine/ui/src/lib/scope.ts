/** API prefix for a project, or the master-key "all projects" routes when none is selected. */
export const scopeBase = (project: string) => (project ? `/v1/projects/${encodeURIComponent(project)}` : "/v1");
