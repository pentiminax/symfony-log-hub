const paths = {
  logs: <path d="M4 6h16M4 12h16M4 18h10" />,
  traces: <path d="M4 5h9M7 12h10M11 19h9" />,
  projects: (
    <path d="M3 7a2 2 0 0 1 2-2h3.6a2 2 0 0 1 1.4.6L11.5 7H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
  ),
  key: (
    <>
      <circle cx="8" cy="12" r="3.2" />
      <path d="M11.2 12H21M18 12v3M15 12v2" />
    </>
  ),
  copy: (
    <>
      <rect x="9" y="9" width="11" height="11" rx="2" />
      <path d="M15 6.5A2.5 2.5 0 0 0 12.5 4H6a2 2 0 0 0-2 2v6.5A2.5 2.5 0 0 0 6.5 15" />
    </>
  ),
  plus: <path d="M12 5v14M5 12h14" />,
  minus: <path d="M5 12h14" />,
  close: <path d="M6 6l12 12M18 6 6 18" />,
  search: (
    <>
      <circle cx="11" cy="11" r="6.5" />
      <path d="m20 20-4.2-4.2" />
    </>
  ),
  "chevron-down": <path d="m6 9 6 6 6-6" />,
  "chevron-up": <path d="m6 15 6-6 6 6" />,
  "chevron-left": <path d="m15 6-6 6 6 6" />,
  "chevron-right": <path d="m9 6 6 6-6 6" />,
  column: <path d="M4 5h16v14H4zM9.5 5v14M14.5 5v14" />,
  inbox: <path d="M4 13l2.5-7h11L20 13v5H4zM4 13h5l1 2h4l1-2h5" />,
  alert: (
    <>
      <path d="M12 8.5v4.5M12 16.4v.2" />
      <circle cx="12" cy="12" r="8.5" />
    </>
  ),
  "sign-out": (
    <>
      <path d="M15 5.5H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-3" />
      <path d="M11 8.5 14.5 12 11 15.5M14 12H4" />
    </>
  ),
};

export function Icon({ name, className = "size-4" }: { name: keyof typeof paths; className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {paths[name]}
    </svg>
  );
}
