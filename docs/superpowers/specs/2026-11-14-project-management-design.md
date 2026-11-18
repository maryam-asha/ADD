# Project management (PM) — design

**Date:** 2026-11-14 · **Status:** approved by the product owner (2026-11-14); phase 1 to build
**Scope:** ADD Core (a new package `add/pm`) + ADD OS dashboard (a new module `pm`).

## Intent

ADD's staff need to plan work as projects and tasks: who does what, by when, and where
it stands — on a board they drag cards across, and in a list. Each person needs one
page of everything on their plate.

This is **not HR.** It started inside the HR timesheets (projects under cost centers,
tasks on a board) and was taken out the same day (2026-11-14), on the product owner's
call, with nothing of it kept: project management is its own module in every ERP
(Odoo Project, Zoho Projects), and HR only *consumes* it — the hours an employee
spends on a task become a cost on their cost center. What was given up: one fewer
package to maintain; what was gained: a task system that does not need HR switched on,
and HR timesheets that point at tasks instead of owning a second copy of them.

## Reference

The product owner's own PM app ("Mudeer", a separate project) was read on
2026-11-14, read-only (screens and the JSON its pages load; nothing created, edited or
completed). What it has, and what this design takes from it:

| Mudeer | Here |
|---|---|
| Projects with a tone (colour), owner, start/end dates, archived flag | Phase 1 |
| Project statuses, configurable per project, each mapped to a fixed category (TODO / IN_PROGRESS / REVIEW / DONE) | Phase 1 has the **four fixed statuses** (product owner's call). The category model is how custom columns would arrive later without breaking reports. |
| Tasks: title, description, status, several assignees, start/due date, priority (Urgent/High/Normal/Low), tags, estimate (hours), subtasks, sort order, completed-at, created-by | Phase 1 |
| Views: Overview, List, Board, Timeline, Notes, Whiteboards | Board + List in phase 1; Overview in phase 2; the rest later |
| My tasks: Assigned to me / Created by me / Everyone; Overdue · Today · Next 7 days · Completed; quick add "for yourself"; grouped by due | Phase 1 |
| Task panel: mark complete, follow, dependencies (blocked), rich-text description, subtasks, notes, attachments, repeat, milestone, custom fields, comments, activity, "h logged" | Phase 1 takes the panel's layout with the phase-1 fields; comments/attachments/activity/follow in phase 2; logged hours in phase 3; the rest later |
| Project overview: open / overdue / due in 7 days / open estimate, progress per status, workload per person, status updates with health (On track …) | Phase 2 |

## Decisions (product owner, 2026-11-14)

1. **A package of its own:** `add/pm` (namespace `Add\Pm`), beside `add/hr` and
   `add/inventory`. Tables `pm_*`, permissions `pm.*`, dashboard module `modules/pm`,
   its own sidebar section "إدارة المشاريع" (not under HR).
2. **Phase 1 is the core** (below). Each phase is reviewed in the browser before the next.
3. **Members only:** a project is seen by its members; whoever holds `pm.projects.manage`
   sees and runs every project.
4. **Fixed columns:** To do · In progress · In review · Done.
5. **Assignment:** a project's owner (and `pm.projects.manage`) creates tasks and assigns
   anyone in the project; any member may also add tasks for themselves.
6. **People are accounts, not employees.** Members and assignees are user accounts, so PM
   works with HR switched off. HR maps an account to its employee when it needs to (phase 3).
7. **Nobody is entered twice.** Members are picked from the people ADD already has — the
   staff on record, whose names, photos and contact details come from where they already
   live. PM keeps only who is in which project; it stores no name, phone or email of its own.
   The package asks a `PeopleDirectory` contract (search, look up by id) that the host binds
   to ADD's staff accounts, the way HR's `PersonStore` works.
8. **Only `pm.projects.manage` creates projects and owns them.** Its holders create a
   project, run it, and name its members; there is no separate "owner" below them. A project
   records who created it and who leads it day to day (`lead_user_id`, optional, any member),
   and the lead may assign tasks inside it.
9. **Priority colours:** Urgent uses the `danger` token, High `warning`, Normal `info`, Low
   neutral — each always with its flag icon and its label, never colour alone (state colour is
   never the sole channel). Urgent is a real alarm about a deadline, which is what `danger`
   means; nothing decorative is orange.

## Phase 1 — data model

```
pm_projects        id, name, description?, tone, lead_user_id?, starts_on?, ends_on?,
                   is_archived, created_by, timestamps
pm_project_members project_id, user_id, unique(project_id, user_id)
pm_tags            id, name, tone                      (shared across projects, as in Mudeer)
pm_tasks           id, project_id, parent_id? (one level of subtasks), title, description?,
                   status (todo|in_progress|review|done), priority? (urgent|high|normal|low),
                   starts_on?, due_on?, estimate_hours?, sort_order, completed_at?,
                   created_by, timestamps
pm_task_assignees  task_id, user_id
pm_task_tag        task_id, tag_id
```

- `tone` is a **name** from a fixed list mapped to ADD's chart tokens, never a hex
  (ADD OS invariant 2). Orange stays reserved for danger: "Urgent" is shown with the
  danger token, an icon and its label — see open question 2.
- A project name is the user's own text (one string, any language), not a `{ar,en}` pair.
- Subtasks are tasks with a `parent_id`; they do not appear as their own cards on the
  board, and their count shows on the parent's card.

## Phase 1 — who may do what

| | `pm.projects.manage` | Lead | Member |
|---|---|---|---|
| See the project and its board | all projects | ✓ | ✓ |
| Create, edit, archive a project; name its members and lead | ✓ | — | — |
| Create a task assigned to anyone in the project | ✓ | ✓ | — |
| Create a task for themselves | ✓ | ✓ | ✓ |
| Edit / delete a task | ✓ | ✓ | the ones they created for themselves |
| Move a task (status, order) | ✓ | ✓ | the ones assigned to them |

`pm.projects.view` (read every project, for reports) is the only other permission.

## Phase 1 — API (`/api/v1/pm`)

```
GET    projects                    mine (all with pm.projects.manage)
POST   projects                    pm.projects.manage
GET    projects/{id}               + members, open/overdue counts
PATCH  projects/{id}               pm.projects.manage
PUT    projects/{id}/members       pm.projects.manage: the whole member list
GET    people?q=                   the directory to pick members and assignees from
GET    projects/{id}/tasks         the board / list; filters: assignee, status, priority, tag, due, q
POST   projects/{id}/tasks
GET    tasks?scope=assigned|created|all   My tasks (all = every project I am in)
GET    tasks/counts                overdue, today, next 7 days, completed
GET    tasks/{id}                  + subtasks
PATCH  tasks/{id}
POST   tasks/{id}/move             { status, order: [ids] } — a dropped card
DELETE tasks/{id}
GET    tags · POST/PATCH/DELETE tags (pm.projects.manage)
```

Every task in a response carries `can: {edit, move, delete, assign}` for the reader, so
the board shows only the controls that work (ADD OS invariant 4: no no-op control).

## Phase 1 — dashboard

- **Projects** — a chip per project (as in the reference), the project header (name, tone,
  owner, dates, done/total), and two tabs: **Board** (the template's Kanban pattern:
  `vuedraggable`, already installed; cards rebuilt in ADD's layer with tokens, not the
  vendor component) and **List** (a table: name, assignees, due, priority, status, tags).
  Filters: search, assignee, status, priority, tag, due.
- **My tasks** — Assigned to me / Created by me / Everyone; the four counters; "add a task
  for yourself" on Enter; groups Overdue · Today · Next 7 days · Later · No date · Completed.
- **Task panel** — a side panel (title, description, subtasks on the left; status,
  assignees, dates, priority, tags, project, estimate on the right; Mark complete on top).

## Later phases

2. **Overview & collaboration:** project overview (progress per status, overdue, workload,
   open estimate), status updates with health, comments, attachments, activity, follow +
   notifications.
3. **Time (HR):** log hours on a task (from the task, or from the timesheet for general work
   on a project); the HR week becomes the sum of those logs and keeps its weekly approval;
   cost by project and cost center. Through a contract HR defines and the host binds — the
   way `PersonStore` works — so neither package reaches into the other. Which cost center a
   project charges is set on the HR side.
4. **Later:** dependencies (blocked), recurrence, milestones and timeline, custom fields,
   notes, sections, custom columns (via the status-category model).

## Questions settled (2026-11-14)

1. ~~Who may own a project?~~ Only holders of `pm.projects.manage` (decision 8).
2. ~~"Urgent" in orange?~~ Left to this design: `danger` with icon and label (decision 9).
3. ~~Members who are not staff?~~ Not wanted: members are the staff already on record,
   picked, never entered again (decision 7). The question was only whether people outside
   the staff (an incubated startup's team) would ever join a project; they will not.
