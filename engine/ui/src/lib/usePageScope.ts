import { useSearchParams } from "react-router";
import { DEFAULT_RANGE, isBounded } from "./range";

/** Project and period selected in the shell header, read from the URL. */
export function usePageScope() {
  const [params] = useSearchParams();
  const scope = {
    project: params.get("project") ?? "",
    range: params.get("range") ?? DEFAULT_RANGE,
    from: params.get("from") ?? "",
    to: params.get("to") ?? "",
  };
  const search = new URLSearchParams(Object.entries(scope).filter(([k, v]) => v && !(k === "range" && v === DEFAULT_RANGE))).toString();
  return { ...scope, ready: isBounded(scope), search: search ? `?${search}` : "" };
}
