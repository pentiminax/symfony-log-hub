/** RFC 4180 field; a leading =, +, - or @ (but not a plain negative number) is prefixed with ' so spreadsheets don't run it as a formula. */
export const csvField = (v: string) => {
  const s = /^[=+\-@\t\r]/.test(v) && !/^-\d+(\.\d+)?$/.test(v) ? `'${v}` : v;
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

export const toCsv = (rows: string[][]) => rows.map((r) => r.map(csvField).join(",")).join("\r\n") + "\r\n";

export function downloadCsv(rows: string[][], now = new Date()) {
  const url = URL.createObjectURL(new Blob([toCsv(rows)], { type: "text/csv;charset=utf-8" }));
  const a = Object.assign(document.createElement("a"), { href: url, download: `logs-${now.toISOString().replace(/[:.]/g, "-")}.csv` });
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
