import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Route, Routes } from "react-router";
import { getKey } from "./api";
import { Logs } from "./Logs";
import { Login } from "./Login";
import { ProjectDetail, ProjectsList } from "./Projects";
import { Shell } from "./Shell";

function Soon({ title }: { title: string }) {
  return (
    <div className="p-6">
      <h1 className="text-lg font-semibold">{title}</h1>
      <p className="mt-1 text-body text-ink-muted">Coming in the next step.</p>
    </div>
  );
}

export function App() {
  const qc = useQueryClient();
  const [key, setKey] = useState(getKey);

  useEffect(() => {
    const out = () => {
      setKey(null);
      qc.clear();
    };
    window.addEventListener("loghub:logout", out);
    return () => window.removeEventListener("loghub:logout", out);
  }, [qc]);

  if (!key) return <Login onLogin={setKey} />;

  return (
    <Routes>
      <Route element={<Shell />}>
        <Route index element={<Logs />} />
        <Route path="logs" element={<Logs />} />
        <Route path="traces" element={<Soon title="Traces" />} />
        <Route path="projects" element={<ProjectsList />} />
        <Route path="projects/:id" element={<ProjectDetail />} />
        <Route path="*" element={<Soon title="Not found" />} />
      </Route>
    </Routes>
  );
}
