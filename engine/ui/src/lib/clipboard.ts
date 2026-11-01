/**
 * Copies `text`, resolving true on success. navigator.clipboard only exists in secure contexts
 * (HTTPS or localhost): over plain HTTP on a LAN address, select `fallback` so the user can copy
 * it by hand, and resolve false.
 */
export function copyText(text: string, fallback: HTMLElement | null): Promise<boolean> {
  const select = () => {
    if (fallback) window.getSelection()?.selectAllChildren(fallback);
    return false;
  };
  if (!navigator.clipboard) return Promise.resolve(select());
  return navigator.clipboard.writeText(text).then(() => true, select);
}
