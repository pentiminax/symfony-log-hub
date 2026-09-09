# LogHub — design system

The world the product is actually built in. Tokens live in
[`assets/styles/app.css`](assets/styles/app.css); this file explains why they are what
they are, so the next screen does not have to re-decide.

## Thesis

A log console is judged on how fast the eye finds the line that explains the incident.
So: no dashboard. No cards, no decorative charts, no chrome that steals pixels from
data. **Density is the product.** Every choice below is downstream of that sentence.

Mode: **Operate.** Scanability, consistency and native affordances outrank expression.
The brand lives in precision — hairlines, rhythm, tabular numerals — not in ornament.

## The world

**Rail.** A near-black column (`--color-rail`, `oklch(0.17 0.008 265)`) that never
scrolls. It is the spatial anchor: wherever you are, you know where the page starts.
Below `md` it collapses into a single dark strip that keeps the product's name and the
way out, because a screen with neither is a screen you cannot leave.

**Content.** Off-white canvas, white surfaces, a sunken tone for code blocks and
hovers. Hairlines are their own token (`--color-line`) rather than a neutral step —
Tailwind's `neutral-200` reads as a bar at 1px on white, and this UI draws a lot of 1px
lines.

**Accent.** One blue. Primary action, current selection, focus ring. Never decoration.
If something is blue, it is actionable or it is where you are.

**Severity.** Six triplets — `dot` (saturated), `tint` (badge ground), `ink` (AA text
on that tint) — for trace / debug / info / warn / error / fatal. The dot never carries
the meaning on its own (spec 07.19): the word is always there next to it. The classes
are written out one per level in `@layer components`, because a class name assembled at
runtime is a class name Tailwind's scanner cannot see.

**Type.** `ui-sans-serif, system-ui` for the interface, `ui-monospace` for data —
timestamps, ids, JSON, stack traces, attribute values. No webfont: a self-hosted
console should paint on the first frame, and the finish here comes from the scale and
the rhythm, not from a typeface with opinions. Fixed rem scale at ~1.125 ratio, from
11px micro-labels to 19px on the login heading. Everything numeric is `tabular-nums`,
so digits do not dance between rows.

**Density.** `--spacing-row: 2rem` (32px) for a table row, `--spacing-control: 2rem`
for a control, so a filter bar and a row share a rhythm. Rail is 200px.

**Motion.** `--duration-state: 140ms`, state transitions only. The single exception is
the drawer's 180ms slide from the right, which says "this came from the row you
clicked". `prefers-reduced-motion` flattens all of it.

Dark mode is not shipped, but every colour is a CSS variable, so it is a swap and not a
rewrite.

## Components

Prefixed `lh-`, defined in `@layer components`:

| Class | Role |
|---|---|
| `.lh-control` | 32px bordered control — selects, chips, inline buttons |
| `.lh-input` | Full-width text input |
| `.lh-button` + `-primary` / `-quiet` | Actions |
| `.lh-severity` + `.lh-severity-dot` + per-level variants | Severity badge |
| `.lh-panel` | Bordered surface with the panel shadow |
| `.lh-rail-link` | Rail navigation, with `aria-current` and `aria-disabled` states |
| `.lh-drawer` | The detail `<dialog>` and its backdrop |
| `.lh-tab` | Drawer section tabs |
| `.lh-row-action` | Per-row affordances in the drawer |

Every interactive element ships default, hover, focus, disabled — and focus is a global
`:focus-visible` ring in `@layer base`, so nothing can forget it.

## Screens

**Sign in** (`templates/security/login.html.twig`). A single centred panel. When the
database holds no user yet it prints the command that creates one, because a
self-hosted install's first run should not be a support ticket.

**Discover** (`/`). The root path *is* the explorer; there is no landing page in front
of it. First viewport: rail, then title + project + range + refresh, a full-width
search field, a filter row, the count and sort, then the table. Nothing needs scrolling
to understand where you are.

**Detail drawer.** A `<dialog>` opened with `showModal()`, pinned right, 46rem wide,
full-screen below `sm`. `showModal()` brings the focus trap, the top layer and Escape
for free; reimplementing those three is how accessible modals go wrong. Six sections —
Overview, Attributes, Resource, Exception, Trace, Raw JSON — as client-side tabs with a
roving tabindex, so reading is never a round trip. Each key/value row offers copy,
filter for, filter out, and add-as-column; below `sm` those affordances stay visible,
since touch has no hover to reveal them with.

## Interaction architecture

Two live components, and the split is not arbitrary: `data-poll` re-renders the
component it sits on. Isolating the table (`LogTable`) is what lets auto-refresh leave
the search box, the open dropdowns and the drawer — all owned by `LogExplorer` —
untouched.

- **URL is the question.** Project, query, range, level, service, environment, ad-hoc
  filters, sort, added columns and the open log are all `LiveProp(url: true)`. A pasted
  link reproduces the screen, columns included. Unset props are `null`, not `''`, so
  they stay out of the query string.
- **Cursors are not.** The cursor stack lives in the child component. Changing the
  question changes the child's `key`, which discards the instance — and the stack with
  it — without a line of reset code.
- **Presets are tokens.** `24h`, never resolved timestamps: each render resolves it
  against `now()`, so a poll slides the window and a shared link means "24 hours before
  *your* now".
- **Errors are inline, in two layers.** A filter the store rejects renders a banner
  inside the table; a failed round trip is caught client-side with
  `controls.displayError = false`, so the rows already on screen are left exactly where
  they are.
- **Polling stops on page 2.** A cursor page is a stable historical slice; refreshing it
  would show the same rows forever. The `data-poll` attribute is simply not rendered
  there.

## Rules for the next screen

1. Reach for a token. If the value you want is not one, add it to `@theme` rather than
   writing a literal.
2. Colour never carries meaning alone. Pair it with a word or a shape.
3. Data is mono and tabular. Interface is sans.
4. New severity or status colours come as a `dot` / `tint` / `ink` triplet, and the
   class is written out, never assembled.
5. Anything that overlays escapes its container: `<dialog>` or a popover, never a
   `z-index` race.
6. Motion is a state change or it does not ship.
