# Satellite Portal — Yearly Update Guide

A reference for updating the WORLDSymposium Satellite Symposium sponsor portal for the next conference year. This covers how the system works, what needs to change, and the order to do it in.

---

## System Architecture

Two WordPress plugins work together:

**wssp-task-content (TC plugin)** — Stores all content: phases, tasks, descriptions, deadlines, form mappings, field keys, sections, and acknowledgment text. Has its own admin UI under "Task Content" in the WP admin menu. This is the single source of truth for what sponsors see.

**ws-satellite-portal** — The sponsor-facing portal. Reads content from the TC plugin, overlays behavioral config (task types, conditional visibility, add-on flags), renders the dashboard, handles form submissions, syncs with Smartsheet, and manages progress tracking.

### How Content Flows

```
TC Plugin DB  →  WSSP_Config::get_phases()  →  WSSP_Dashboard  →  Templates
                      ↑                              ↑
              portal-config.php              Formidable + session_meta
              (behavioral overrides)         (status, submitted data)
```

### Key Config File

`ws-satellite-portal/config/portal-config.php` — Contains only behavioral overrides. Tasks default to `type=form, owner=sponsor`. Only list tasks that differ. This file also has `file_types` and `vendor_views`.

---

## Yearly Update Checklist

### 1. Update Shortcode Dates

The system uses WordPress shortcodes for dates (e.g., `[awards-deadline]`, `[cancellation-deadline]`, `[satellite-late-info-deadline]`). These are defined in the theme or a mu-plugin via `$world_data` / `shortcodes-world.php`.

**Update the shortcode values for the new conference year.** Task deadlines and phase date ranges reference these shortcodes by key — the TC plugin stores the shortcode key (e.g., `satellite-late-info-deadline`), and the portal resolves it at render time via `do_shortcode()`.

### 2. Update TC Plugin Content

In WP Admin → Task Content → select the "satellite" portal:

- **Phase dates** — Start Date and End Date are stored but no longer displayed in the portal. They accept either a date string (`2027-10-01`) or a shortcode key (`awards-deadline`). These fields are available for future use but currently have no effect on rendering.
- **Task deadlines** — Review and update deadline values on each task. Same format: date string or shortcode key.
- **Task descriptions** — Update any year-specific content in descriptions and section content (conference year, pricing, etc.).
- **Form keys and field keys** — These should remain stable year-to-year unless Formidable forms change.

**Tip:** Use Export/Import to back up the current year's content before making changes. The export is a JSON file you can re-import.

### 3. Review Add-Ons

Add-ons are defined by the `manage-add-ons` phase in the TC plugin. Each task in that phase is an add-on.

**Convention:** Task slugs in this phase must end in `-addon` (e.g., `push-notification-addon`, `recording-addon`). The addon slug for `session_meta` is derived automatically:

| TC task slug | Derived meta key |
|---|---|
| `push-notification-addon` | `addon_push_notification` |
| `additional-fts-addon` | `addon_additional_fts` |
| `recording-addon` | `addon_recording` |

If you add a new add-on:
1. Create the task in the `manage-add-ons` phase with a slug ending in `-addon`
2. Set the form_key and field_keys to the corresponding Formidable request field
3. If the add-on has related tasks in other phases (e.g., "Submit Push Notification Text"), add an entry in `portal-config.php` under `task_behavior` with `'addon' => 'derived_slug'`
4. Add the Formidable field key to `addon_field_keys` in `portal-config.php` as a fallback mapping (in case the TC plugin field_keys don't match or aren't set)

**Important:** The add-on form fields should offer "Request" (or similar) and "No" as options. The system detects "No", "Decline", "Declined", and "Not interested" as decline responses (case-insensitive). Any other non-empty value is treated as a request.

### 4. Review Behavioral Config

Check `ws-satellite-portal/config/portal-config.php`:

- **Task behavior** — Verify task slugs still match TC plugin. Only tasks that differ from the default (type=form, owner=sponsor, completable=true) need entries. Available overrides:
  - `type` — `form`, `upload`, `info`, `review_approval`, `approval`
  - `owner` — `sponsor` (default) or `logistics`
  - `addon` — gates the task behind an add-on purchase (slug must match derived addon slug)
  - `condition` — conditional visibility rule (must have a matching entry in `WSSP_Condition_Evaluator::get_rules()`)
  - `completable` — set to `false` for tasks that have a form but should never show a checkbox or count toward progress (e.g., logistics contacts that can be updated indefinitely)
  - `file_type` — for upload tasks, references a key in the `file_types` config
- **Add-on field keys** — Verify `addon_field_keys` maps each addon slug to the correct Formidable field key. This is the fallback when TC plugin `field_keys` don't match.
- **File types** — Update allowed extensions or labels if needed.
- **Vendor views** — Update field lists if Formidable fields changed.

### 5. Smartsheet Integration

- **Field map** — Review `config/smartsheet-field-map.php` for any new or renamed columns.
- **Session meta keys** — The Smartsheet sync populates `wssp_session_meta`. Addon purchases from the application are stored as `addon_<slug> = 'yes'`. Verify the slugs match the TC-derived addon slugs (step 3 above).

### 6. Formidable Forms

- Verify the consolidated session data form (`wssp-sat-session-data`) has all necessary fields.
- Verify field keys in the TC plugin match the Formidable field keys.
- If you add new fields, add them to the relevant task's `field_keys` array in the TC plugin.
- Verify the meeting planner form (`wssp-sat-meeting-planners`) field keys match those in `class-wssp-rest-meeting-planners.php` (see Meeting Planner CRUD below).

### 7. Review Meeting Planner Form Fields

The meeting planner CRUD system uses field key constants defined in `class-wssp-rest-meeting-planners.php`. If the Formidable form fields change, update the `$fields` and `$address_fields` arrays in that class. The field keys referenced are:

| Field Key | Label | Required |
|---|---|---|
| `wssp-sat-mp-first-name` | First Name | Yes |
| `wssp-sat-mp-last-name` | Last Name | Yes |
| `wssp-sat-mp-degrees` | Degree(s) | No |
| `wssp-sat-mp-company` | Company | Yes |
| `wssp-sat-mp-email` | Email | Yes |
| `wssp-sat-mp-mobile` | Mobile | No |
| `wssp-sat-mp-address` | Address (serialized) | No |
| `wssp-sat-mp-uid` | User ID (hidden) | Auto |
| `wssp-sat-mp-sat-key` | Session Key (hidden) | Auto |

### 8. Clear Caches

After all updates:
- Clear any WordPress object cache
- The TC plugin uses `wp_cache` (per-request, non-persistent) so no special cache clearing needed there
- The portal's `WSSP_Config` caches phases per request — no persistent cache

---

## How Key Features Work

### Real-Time Dashboard Refresh (Server-Rendered Partials)

The portal uses a server-rendered partials architecture for UI updates. After any mutation (form submit, checkbox click, acknowledgment), the JS calls a single REST endpoint that re-renders the affected UI regions using the same PHP templates as the full page load, then swaps them into the DOM.

**Why this approach:** Earlier versions used manual DOM manipulation (swapping classes, rebuilding SVG icons, toggling visibility with timers and delays). This was fragile — some elements would update and others wouldn't, requiring a page reload to see the full picture. The server-rendered partials approach ensures the client always matches what a full page load would produce, because it's literally the same PHP templates rendering both.

**The refresh endpoint:** `GET /wssp/v1/session/refresh?session_id=X` returns:

```json
{
  "success": true,
  "partials": {
    "session_overview": "<div class=\"wssp-overview-row\">...",
    "task_cards": {
      "program-title": "<div class=\"wssp-task-card\" ...",
      "submit-description": "<div class=\"wssp-task-card\" ..."
    },
    "phase_progress": {
      "program-development": { "done": 3, "total": 5, "html": "<span class...>" }
    },
    "task_modals": {
      "submit-title": "<div class=\"wssp-modal\" ..."
    },
    "stats": { "completed": 3, "total": 12, "due_this_week": 2, "overdue": 0 }
  }
}
```

**How `refreshDashboard()` works (in `portal.js`):**

1. Saves current accordion open/close states
2. Calls the refresh endpoint
3. Swaps the session overview card (including progress sidebar)
4. Swaps each task card by `data-task-key`
5. Updates phase progress counter HTML
6. Swaps task modals — but skips any modal that is currently open (stores the fresh HTML as `_pendingPartial` and applies it when the user closes the modal)
7. Restores accordion states
8. Re-binds event listeners on swapped elements (checkboxes, modal buttons, form drawer buttons)

**What gets preserved:** Accordion open/close state, scroll position, any open modal content (deferred swap on close).

**What triggers a refresh:**
- Form submitted in the drawer (via `postMessage` from the iframe → immediate `refreshDashboard()` call, no timer or delay)
- Task checkbox marked complete (after successful REST submit)
- Task acknowledgment checkbox checked (after successful REST acknowledge)

**Extending with new partials:** To add a new mutable UI region to the refresh, add its HTML to the `refresh_session_partials()` method in `class-wssp-rest.php`, then add the corresponding DOM swap logic in `portal.js`'s `refreshDashboard()` function.

### Form Drawer Modes

The form drawer operates in three modes depending on the task:

**Iframe mode (default):** Standard Formidable forms load inside an iframe via `form-embed-template.php`. The iframe's script detects form submission (via `.frm_message` element) and sends `postMessage` to the parent. The parent calls `refreshDashboard()` immediately — no timer, no waiting for the drawer to close.

**Readonly mode:** When a task is marked complete, the "Open Form" button becomes "View Response" with an eye icon. The form loads in the iframe but JS injects: disabled inputs, hidden submit button, a "View Only" notice at the top, and a "Close" button at the bottom. The sponsor can review their responses but cannot edit them.

**CRUD mode (meeting planners):** Multi-entry forms like meeting planners bypass the iframe entirely. Instead, the drawer loads a custom CRUD panel via `GET /wssp/v1/meeting-planners`. See "Meeting Planner CRUD" below.

The `CRUD_FORM_KEYS` array at the top of `form-drawer.js` controls which form keys use CRUD mode instead of the iframe:
```javascript
var CRUD_FORM_KEYS = ['wssp-sat-meeting-planners'];
```

To add another multi-entry form to CRUD mode, add its form key to this array and create a corresponding REST controller class.

### Meeting Planner CRUD

Meeting planner entries are managed through a custom CRUD system (`class-wssp-rest-meeting-planners.php`) that replaces the previous Formidable View + iframe approach. This eliminates the "filter flash" problem where all entries briefly appeared before the session filter kicked in.

**Endpoints:**

| Method | URL | Action |
|---|---|---|
| `GET` | `/wssp/v1/meeting-planners?session_id=X` | List planners + add form |
| `POST` | `/wssp/v1/meeting-planners` | Create new planner |
| `POST` | `/wssp/v1/meeting-planners/{id}/update` | Update existing planner |
| `POST` | `/wssp/v1/meeting-planners/{id}/delete` | Delete planner |

**Why POST for update/delete instead of PUT/DELETE:** Many server configurations (Apache with mod_security, certain nginx setups) block or misroute PUT and DELETE HTTP methods. Using POST with distinct URL patterns (`/update`, `/delete`) is universally supported and unambiguous.

**How data is stored:** Entries are standard Formidable entries in the `wssp-sat-meeting-planners` form. The CRUD endpoints read and write directly to Formidable's `frm_items` and `frm_item_metas` tables, and entries appear normally in the Formidable admin.

**Hidden fields populated automatically:**
- `wssp-sat-mp-sat-key` — Session key (links the entry to the session)
- `wssp-sat-mp-uid` — Current user ID

**Address field handling:** Formidable stores addresses as serialized PHP arrays:
```
a:5:{s:5:"line1";s:11:"2222 Street";s:4:"city";s:7:"Chicago";s:5:"state";s:2:"IL";s:3:"zip";s:5:"55555";s:7:"country";s:13:"United States";}
```

The CRUD system handles this with:
- **Reading:** `parse_address()` unserializes the value (handles serialized arrays, raw arrays, and plain string fallbacks for legacy data)
- **Creating:** `FrmEntry::create()` receives the address as a raw PHP array — Formidable serializes it internally
- **Updating:** `FrmEntryMeta::update_entry_meta()` cannot handle array values (it tries to use the value as a cache key, causing errors). So address fields are updated via direct `$wpdb->update()` on `frm_item_metas` with `maybe_serialize()` applied once. Non-array fields use `FrmEntryMeta::update_entry_meta()` normally.

**Important: Do not use `FrmEntry::update()` for field values.** It doesn't reliably handle `item_meta` — it can corrupt or disassociate entries. Always use `FrmEntryMeta::update_entry_meta()` for scalar values and direct DB updates for array values (like addresses).

**Adding a new CRUD form:** To add another multi-entry form (e.g., material uploads):
1. Create a new class following the pattern of `class-wssp-rest-meeting-planners.php`
2. Define the field keys, form key, and session key field
3. Register it in `ws-satellite-portal.php` bootstrap
4. Add the form key to `CRUD_FORM_KEYS` in `form-drawer.js`
5. Add CSS if the form layout differs from meeting planners

### Date Resolution

`WSSP_Config::resolve_date()` handles the shortcode-or-date ambiguity:
1. Try `strtotime()` on the raw value → if it parses, return `Y-m-d`
2. If not, try `do_shortcode('[value]')` → strip HTML tags → try `strtotime()` → return `Y-m-d`
3. If the shortcode resolves but can't be parsed as a date, return the clean string
4. If nothing resolves, return `null`

**Important:** Shortcodes must be registered (via `add_shortcode()`) before `get_phases()` is called. Since `get_phases()` is lazy (called during template rendering, after `init`), this is normally fine.

**Debug shortcode highlighting:** If you have a shortcode debug function that wraps output in styled `<span>` tags, `resolve_date()` calls `wp_strip_all_tags()` on the resolved value before parsing. This handles the debug case, but dates may not format correctly while debug highlighting is active.

### Add-On Lifecycle

From the sponsor's perspective, add-ons have three states:

| State | Session Overview Pill | Task Card | How it gets here |
|---|---|---|---|
| **Available** | `+ Push Notifications` (clickable button) | Unchecked, "Open Form" button | Default — no form data submitted yet |
| **Active** | `✓ Push Notifications` (black, static) | Checked as complete | Meta `addon_<slug> = 'yes'` OR form value is a request |
| **Declined** | `✕ Push Notifications` (gray, static) | Checked as complete | Form value is "No" / "Decline" |

An add-on is "active" if **either**:
- `session_meta` has `addon_<slug> = 'yes'` or `'hold'` (Smartsheet-confirmed — always wins), OR
- The Formidable request field has a non-empty value that is NOT a decline keyword

An add-on is "declined" if:
- The Formidable field value is `"No"`, `"Decline"`, `"Declined"`, or `"Not interested"` (case-insensitive)
- AND there is no Smartsheet meta override

Both active and declined add-ons are treated as "responded" — the task card shows as completed because the sponsor has made their decision. The admin can reset a declined add-on by clearing the Formidable field value in wp-admin (the `bypass_required_in_admin` filter allows saving partial entries).

**Field key resolution:** The Formidable field key for each add-on is resolved in this order:
1. TC plugin task `field_keys[0]` (primary — same keys used to show form fields in the drawer)
2. `portal-config.php` → `addon_field_keys` map (fallback for when TC field_keys don't match)

**Add-on gated tasks:** Tasks in OTHER phases can be gated behind an add-on purchase using `'addon' => 'slug'` in `portal-config.php`. These tasks are excluded from the total count (and hidden from the phase progress counter) when the add-on hasn't been purchased.

**Code path:** `WSSP_Public::get_addon_states()` returns the three-state map. `get_purchased_addons()` is a convenience wrapper returning only active slugs for backwards compatibility.

### Conditional Task Visibility

Tasks can be conditionally shown/hidden based on form data using the condition evaluator system.

**How to add a conditional task:**
1. In `portal-config.php` → `task_behavior`, add: `'my-task' => array( 'condition' => 'my_condition_slug' )`
2. In `includes/class-wssp-condition-evaluator.php` → `get_rules()`, add a matching rule:
   ```php
   'my_condition_slug' => function ( $data ) { return (bool) $data['some_field']; },
   ```

**Current conditions:**
- `ce_path` — visible when `wssp_program_ce_status` indicates CE accreditation
- `non_ce_path` — visible when CE is NOT selected (the default)

**How it works at render time:**
- The dashboard engine flags tasks with `is_hidden = true` when their condition fails
- Hidden tasks are rendered into the HTML with `style="display:none;"` (not excluded)
- This allows the JS to toggle visibility without a page reload when the form data changes

**How it works after form submit:**
- `refreshDashboard()` calls the session refresh endpoint, which re-evaluates all conditions
- Fresh task card partials include the correct `display:none` or visible state
- Phase progress counters are recalculated server-side

### Task Status & Completion Logic

A task is considered "done" (checked, completed) when **any** of these are true:
- `wssp_task_status` table has status `approved` or `complete` (`is_done` from dashboard engine)
- `wssp_task_status` table has status `submitted_by_sponsor` (`is_submitted` from dashboard engine)
- Task is an add-on (`-addon` suffix) and the sponsor has responded — either active or declined

**Completed tasks with forms:** When a task is marked complete and has a form, the "Open Form" button changes to "View Response" (eye icon). Clicking it opens the form in readonly mode — all fields disabled, submit button hidden, "View Only" notice shown, "Close" button at the bottom.

**Checkbox disabled when:**
- Task type is `info`
- User doesn't have edit permission
- Task is already done/submitted
- Task requires acknowledgment and hasn't been acknowledged yet
- Task has a form with `field_keys` and none of those fields have any value in `$session_data` (form not started)

**Task priority badges** are computed dynamically from deadline proximity:
- **overdue** (red): past deadline
- **high** (orange): due within 3 days
- **medium** (amber): due within 14 days
- **low** (gray): more than 14 days out
- No badge: task has no deadline, is info type, or is already done

The status tag always shows the actual deadline date. For overdue tasks: "Due Nov 4 (3d overdue)".

### Phase Status Badges

Phases show a badge only for two states:
- **Completed** (green) — all actionable (non-info, non-hidden) tasks are done
- **Overdue** (red) — at least one task is past its deadline

No badge is shown for phases that are in progress or upcoming. Phase progress count (e.g., "3/5 completed") accounts for add-on responded states and submitted tasks.

**Phase accordion state** is persisted in `sessionStorage` so open/closed phases survive page reloads and dashboard refreshes within the same browser tab.

### Phase Info Modals

Phases can have their own content sections (stored in the TC plugin with `parent_type = 'phase'`). When a phase has sections, a small ⓘ icon appears in the phase header. Clicking it opens a modal with the phase content — same markup as task More Info modals.

The ⓘ button click does not trigger the accordion toggle (propagation is stopped in the JS).

### Review Required / Acknowledgment Flow

Tasks with `requires_acknowledgment = true` in the TC plugin show a "Review Required" button instead of "More Info". The flow:

1. Sponsor clicks "Review Required" → modal opens with content + acknowledgment checkbox
2. Sponsor reads content, checks the checkbox → REST call saves `acknowledged_at` to `wssp_task_status`
3. Modal shows "Acknowledged — you may close this window" (does NOT auto-close)
4. Meanwhile, `refreshDashboard()` updates the task card and phase progress behind the modal. The modal itself is NOT swapped while open — its fresh HTML is stored as `_pendingPartial` on the element.
5. Sponsor closes modal manually → deferred partial is applied, replacing the modal with the server-rendered version. The card now shows:
   - Badge changes from "Review Required" to "Acknowledged"
   - "Review Required" button replaced with "More Info" button
   - Task checkbox becomes enabled
   - Modal type switches from `review_required` to `more_info`
6. On subsequent opens, the modal shows the content with a confirmed acknowledgment section at the bottom — a green checkmark with the original acknowledgment text (read-only, no checkbox), so the sponsor can be reminded of what they agreed to.

### Date Override (Admin Dev Tool)

Admins (`manage_options` capability) see a date dropdown in the header instead of a static date. Selecting a date adds `?wssp_date=YYYY-MM-DD` to the URL, causing the entire dashboard to render as if that were "today".

**What it affects:** Overdue/upcoming task classification, priority badges, phase status badges, dashboard stats (completed/due this week/overdue), status tags on task cards.

**What it does NOT affect:** Write operations (submitted_at, reviewed_at, acknowledged_at all use the real date).

**Dropdown options** are auto-generated from actual task deadlines: 3 days before each deadline (upcoming), the deadline day (due today), and 1 day after (overdue). The first option is always "Today (real)".

`WSSP_Date_Override::get_today()` is the central helper used by all date-sensitive code. When no override is active, it returns `current_time('Y-m-d')`.

### Admin Validation Bypass

The `WSSP_Formidable::bypass_required_in_admin()` filter clears all Formidable validation errors when editing entries in wp-admin. This allows admins to save partial entries (e.g., resetting a single add-on field) without filling every required field. Only applies to WSSP forms, only in `is_admin()` context.

### Field Rendering

`wssp_render_field($value, $mode)` in `includes/wssp-render-helpers.php`:

| Mode | Pipeline | Use for |
|---|---|---|
| `'rich'` | `do_shortcode( wpautop( wp_kses_post( $value ) ) )` | description, form_instructions, section content |
| `'plain'` | `esc_html( $value )` | title, acknowledgment_text, headings |
| `'nl2br'` | `wp_kses_post( nl2br( esc_html( $value ) ) )` | Formidable textarea values |

### CE Accreditation Logic

CE accreditation affects three things:

1. **Conditional tasks:** `ce-accreditation` task is visible when CE is selected; `non-ce-accreditation` is visible when CE is NOT selected (default). Controlled by `WSSP_Condition_Evaluator` rules `ce_path` and `non_ce_path`, reading `wssp_program_ce_status` from Formidable.

2. **Session overview badge:** The "CE Accredited" badge shows when `wssp_program_ce_status` is not empty, not 'non-ce', and not 'no'.

3. **Company name override:** CE accredited sessions show `wssp_data_supported_by` instead of the company name.

### Audience Restriction Logic

When `wssp_program_audience_type` doesn't contain "All":
- The "Restricted Audience" badge shows
- The restriction description (`wssp_program_intl_audience_desc`) shows below the company name

---

## File Reference

### Portal Plugin (ws-satellite-portal)

| File | Purpose |
|---|---|
| `ws-satellite-portal.php` | Bootstrap, dependency gate, service wiring |
| `config/portal-config.php` | Behavioral overrides, file types, vendor views, addon field key map |
| `config/smartsheet-field-map.php` | Smartsheet column mapping |
| `includes/class-wssp-config.php` | Merges TC content + behavioral config, resolves dates, loads phase sections |
| `includes/class-wssp-condition-evaluator.php` | Conditional task visibility rules (CE/Non-CE, extensible) |
| `includes/class-wssp-date-override.php` | Admin date override for testing (`?wssp_date=`) |
| `includes/class-wssp-task-content.php` | Adapter — delegates to TC plugin |
| `includes/wssp-render-helpers.php` | `wssp_render_field()` helper |
| `includes/class-wssp-dashboard.php` | Enriches phases with status, progress, condition filtering |
| `includes/class-wssp-formidable.php` | Formidable integration, entry linking, admin validation bypass |
| `includes/class-wssp-rest.php` | REST endpoints: task submit, acknowledge, task visibility, session refresh (partials) |
| `includes/class-wssp-rest-meeting-planners.php` | Meeting planner CRUD: list, create, update, delete via REST |
| `includes/class-wssp-session-meta.php` | Session meta CRUD |
| `public/class-wssp-public.php` | Public rendering, `get_addon_states()`, `get_purchased_addons()`, stats, `sessionId` in localized JS data |
| `public/views/session-overview.php` | Session info card, badges, three-state add-on pills |
| `public/views/dashboard-header.php` | Header bar, session selector, admin date override dropdown |
| `public/views/dashboard-phases.php` | Phase accordions, progress counts, phase info button |
| `public/views/task-card.php` | Task card: dynamic priority, form completion check, addon state, "View Response" for completed tasks |
| `public/views/task-modal.php` | Task + phase More Info modals, acknowledgment flow, confirmed acknowledgment display |
| `public/views/form-embed-template.php` | Iframe form page with postMessage (used by single-entry forms only) |
| `public/js/portal.js` | `refreshDashboard()`, checkboxes, accordions (sessionStorage), modals with deferred swap, acknowledgment flow |
| `public/js/form-drawer.js` | Drawer open/close, iframe mode, readonly mode, CRUD mode (meeting planners), `postMessage` handling |
| `public/css/portal.css` | Main portal styles including confirmed acknowledgment state |
| `public/css/form-drawer.css` | Drawer panel, loading, error states |
| `public/css/meeting-planners.css` | Meeting planner CRUD panel: list, inline edit, add form, 6-column grid layout |

### TC Plugin (wssp-task-content)

| File | Purpose |
|---|---|
| `includes/class-wssp-tc-task-content.php` | DB operations, cache, export/import |
| `includes/class-wssp-tc-activator.php` | Table creation (phases, tasks, sections) |
| `admin/class-wssp-tc-admin.php` | Admin UI, AJAX handlers |
| `admin/views/overview.php` | Tree + editor admin page |
| `admin/js/admin.js` | Tree rendering, editor interactions |

---

## Troubleshooting

**Dashboard not updating after form submit:** The `refreshDashboard()` function in `portal.js` calls `GET /session/refresh`. Check the browser Network tab for this request. If it returns successfully but the DOM doesn't update, check that `wsspData.sessionId` is set (it's passed via `wp_localize_script` in `class-wssp-public.php`). If `sessionId` is `0` or missing, the refresh can't fire.

**Badges not showing:** Check that `$session_data` has the Formidable field values. The merge priority is Formidable > session_meta > session table. If the Formidable entry isn't linked, check that `frm_entry_id` is set in the sessions table.

**Dates not resolving:** Check that shortcodes are registered before template rendering. If using debug shortcode highlighting, dates wrapped in styled spans won't parse — `resolve_date()` strips tags but the debug output can still interfere.

**Add-on pills not updating after form submit:** Check browser console for `[WSSP Drawer]` messages. After form submit, `refreshDashboard()` fetches all partials including the session overview. Verify the session refresh endpoint returns correct `addon_states` in the overview partial.

**Add-on tasks not marking complete:** Verify the Formidable field key in the TC plugin's task `field_keys` matches the actual field key in Formidable. If they don't match, add a fallback entry in `portal-config.php` → `addon_field_keys`. Add debug logging in `task-card.php` to check `$addon_state` values.

**Phase showing "Overdue" when all tasks are done:** The dashboard engine needs `$addon_states` to know add-on tasks are complete. Verify `get_dashboard_data()` is receiving the `$addon_states` parameter. Also check that non-completable tasks (`completable => false`) aren't being counted.

**Task count wrong in Task Progress:** `compute_dashboard_stats()` skips info tasks, hidden tasks, non-completable tasks, and add-on gated tasks that aren't purchased. It counts `is_done`, `is_submitted`, and addon-responded tasks as completed. If counts seem off, check that the task's `type`, `completable`, and `addon` config values are correct.

**Checkbox won't enable:** The checkbox is disabled when: task is info type, user can't edit, task is already done, task needs acknowledgment, or the task's form fields are all empty in `$session_data`. Check the form completion logic — it requires at least one `field_keys` value to be non-empty.

**Modal not opening after acknowledgment:** This happens when the task card button says "More Info" (`data-modal-type="more_info"`) but the modal element still has `data-modal-type="review_required"`. The `refreshDashboard()` function swaps the modal partial — but if the modal was open during the refresh, the swap is deferred via `_pendingPartial` and applied on close. If the deferred swap isn't working, check the `closeAllModals()` function in `portal.js`.

**Meeting planner update deleting entries:** If `FrmEntry::update()` is used instead of `FrmEntryMeta::update_entry_meta()`, entries can be corrupted or disassociated. The correct pattern is to loop over `$item_meta` and call `FrmEntryMeta::update_entry_meta()` for each scalar field, and use direct `$wpdb->update()` for array fields (like addresses). See "Meeting Planner CRUD" above.

**Meeting planner address double-serialized:** If `maybe_serialize()` is called before passing to `FrmEntryMeta::update_entry_meta()`, the value gets serialized twice (the function serializes internally). For array values, bypass `FrmEntryMeta` and use direct `$wpdb->update()` with `maybe_serialize()` applied exactly once.

**Date override not working:** Only available to users with `manage_options` capability. Check the `?wssp_date=` query parameter is present. The override affects rendering only — write operations always use the real date.

**TinyMCE in TC admin:** The editors use WordPress's `teeny` mode. Paste cleanup was attempted but reverted due to TinyMCE toolbar/bookmark issues. Content from web pages may include extra markup — clean it manually in the Text (code) tab.

**Phase dates blank:** Verify the TC plugin was deactivated and reactivated after the `start_date`/`end_date` columns were added. `dbDelta` only runs on activation. Note: phase dates are stored but no longer displayed in the portal.

---

## Formidable API Gotchas

Lessons learned from building the meeting planner CRUD:

| Operation | Correct Approach | Wrong Approach |
|---|---|---|
| Create entry | `FrmEntry::create( ['form_id' => X, 'item_meta' => [...]] )` — pass arrays directly, Formidable serializes | — |
| Update scalar field | `FrmEntryMeta::update_entry_meta( $entry_id, $field_id, null, $value )` | `FrmEntry::update( $id, ['item_meta' => [...]] )` — corrupts entries |
| Update array field (address) | Direct `$wpdb->update()` on `frm_item_metas` with `maybe_serialize()` | `FrmEntryMeta::update_entry_meta()` with an array — causes cache key errors and potential double-serialization |
| Delete entry | `FrmEntry::destroy( $entry_id )` | — |
| Read entry fields | Direct DB query on `frm_item_metas` with field ID map | — |
| Register PUT + DELETE on same route | Use separate URL patterns (`/update`, `/delete`) both as POST | Registering PUT and DELETE on the same `register_rest_route()` pattern — second call can overwrite first |

---

## Files to Never Delete

These files are gone from the repo but were replaced — don't recreate them:

| Deleted File | Replaced By |
|---|---|
| `config/event-types.php` | `portal-config.php` + TC plugin |
| `config/task-content.php` | TC plugin database |
| `config/task-display.php` | TC plugin task descriptions |
| `admin/views/task-content-editor.php` | TC plugin admin |
| `admin/js/task-content-editor.js` | TC plugin admin |
