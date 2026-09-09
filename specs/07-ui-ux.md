# 7. UI / UX Specification

## 7.1 Product direction

The UI should feel like **Kibana Discover reduced to the essentials**.

Primary goals:

- find a log in a few seconds
- understand active filters immediately
- add/remove columns without configuration screens
- inspect one log without losing current search context
- remain visually calm under dense data

Avoid dashboard-like visual noise.

## 7.2 Visual language

Use a minimal application shell:

- dark or near-black compact left sidebar
- light main content area
- one primary accent color
- neutral borders
- restrained shadows
- compact but readable density
- semantic severity badges/icons
- monospace only for IDs, paths, stack traces, JSON, timestamps where useful

Avoid gradients, oversized cards, decorative charts, or marketing-style surfaces.

## 7.3 Global layout

```text
┌──────────────┬──────────────────────────────────────────────────┐
│ LogHub       │ Project selector        User / Settings          │
│              ├──────────────────────────────────────────────────┤
│ Logs         │ Search                                  Refresh │
│ Projects     │ Time range             Auto-refresh             │
│ Settings     ├──────────────────────────────────────────────────┤
│              │ Active filters                                  │
│              ├──────────────┬───────────────────────────────────┤
│              │ Fields       │ Logs table                        │
│              │              │                                   │
│              │              │                                   │
└──────────────┴──────────────┴───────────────────────────────────┘
```

## 7.4 Logs / Discover screen

This is the default authenticated screen.

### Header controls

Top row:

- project selector
- global search input
- time range selector
- refresh button
- auto-refresh selector

Recommended auto-refresh values:

- Off
- 5 s
- 10 s
- 30 s
- 1 min

### Search input

Placeholder example:

`Search message or exception…`

Do not expose a query language in V1.

### Time range

Provide quick presets:

- Last 15 minutes
- Last 1 hour
- Last 24 hours
- Last 7 days
- Custom

The current range must always be visible.

## 7.5 Active filter bar

Filters render as compact removable chips:

```text
severity = ERROR   ×
service.name = billing-api   ×
attributes.provider = stripe   ×
```

Interactions:

- click chip → edit filter
- click × → remove
- `Clear all` when at least two filters are active

Filters must never be hidden in an off-screen modal after application.

## 7.6 Field sidebar

Left panel within Discover contains:

- search fields input
- `Selected fields`
- `Available fields`

Example:

```text
Fields
[ search fields… ]

Selected
✓ Timestamp
✓ Level
✓ Message
✓ Service

Available
  environment
  payment_id
  provider
  retry
```

Clicking an available field adds it as a visible table column.

Selected columns can be reordered or removed.

The field catalog should show the detected type where useful.

## 7.7 Logs table

Default columns:

1. timestamp
2. severity
3. message
4. service
5. environment

Optional dynamic columns come from the field catalog.

Behavior:

- newest first
- sticky header
- compact row height
- truncate long message visually, preserve full value in detail
- severity shown with badge/dot + text, never color alone
- column sorting only where supported by the query contract
- per-column filter action available from header or cell context

Do not display 15 default columns.

## 7.8 Cell interactions

For structured values, provide a compact context menu:

```text
Filter for value
Filter out value
Copy value
```

Example: clicking `stripe` in provider can create:

`attributes.provider = stripe`

This is one of the primary fast-exploration interactions.

## 7.9 Row interaction

Clicking a row opens a **right-side detail drawer** rather than navigating away.

Requirements:

- preserve current filters/search/scroll position
- drawer width approximately 40–50% on desktop
- full-screen sheet on narrow layouts
- URL may contain selected log ID for reload/shareability
- Escape closes drawer

## 7.10 Log detail drawer

Header:

- severity
- message
- timestamp
- service
- copy log ID
- close action

Sections/tabs:

1. Overview
2. Attributes
3. Resource
4. Exception
5. Trace
6. Raw JSON

### Overview

Show only high-value data:

- timestamp
- service
- environment
- host
- channel
- request ID if present
- user ID if present
- trace ID if present

### Attributes / Resource

Render as key/value rows.

Each value supports:

- filter for
- filter out
- copy

Nested structures should be expandable rather than flattened into unreadable strings.

### Exception

If present:

- exception type
- message
- code
- stacktrace

Stacktrace uses a monospace scrollable block with copy action.

### Raw JSON

Pretty-printed canonical log representation with copy action.

## 7.11 Empty states

### No logs in selected range

Message:

`No logs found in this time range.`

Actions:

- expand time range
- clear filters if any

### No result after filters

Message:

`No logs match the current filters.`

Primary action:

`Clear filters`

Do not show generic illustration-heavy empty states.

## 7.12 Loading states

Use table skeleton rows for initial load.

For auto-refresh:

- keep existing rows visible
- show subtle refresh indicator
- do not blank the table

## 7.13 Error states

Search/API errors should render as a compact inline banner above the table.

Example:

`Logs could not be refreshed. Showing the last successful result.`

Do not replace the full page for transient refresh failures.

## 7.14 Project selector

Project is the primary workspace boundary.

Switching project:

- updates field catalog
- updates search results
- clears incompatible filters
- may retain generic UI preferences such as row density

## 7.15 Projects screen

Minimal management table:

- name
- API key status
- retention
- observed services
- last log received

Project detail allows:

- rename
- retention setting
- API key rotation/revocation
- list observed services

No dashboard analytics in V1.

## 7.16 Settings screen

Only include settings that exist in V1:

- default retention
- user/account management if necessary
- UI preferences if not local only

Avoid generic placeholder settings sections.

## 7.17 Persistence of UI preferences

V1 can persist locally:

- visible columns
- column order
- auto-refresh selection
- preferred time range

Use localStorage initially.

Server-side named/saved views are deferred.

## 7.18 Responsive behavior

Desktop is the primary target.

Tablet/mobile behavior:

- sidebar collapses
- fields panel becomes a drawer
- log detail becomes full-screen
- table remains horizontally scrollable where unavoidable

Do not attempt to make dense log exploration look like a card feed on mobile.

## 7.19 Accessibility

- severity meaning must not rely on color alone
- keyboard focus must be visible
- drawer must trap focus while open
- Escape closes transient layers
- all menus operable by keyboard
- minimum contrast consistent with WCAG AA target
- table headers must be programmatically associated with columns

## 7.20 Approved visual references

The `mockups/` directory contains the approved direction. They are visual references, not pixel-perfect implementation contracts.
