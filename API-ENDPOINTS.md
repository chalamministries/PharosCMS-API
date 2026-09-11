# PharosCMS API Endpoints

All endpoints are served over HTTPS at `https://api.pharoscms.com`.

> ✅ **This document is the single source of truth.**
> All frontend calls (`client-auth.js`, `client-portal.js`, `assets/js/`) must use these paths.

---

## 🔐 Authentication

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/auth/login` | Client/investigator/admin login → returns JWT |
| `POST` | `/auth/logout` | Invalidate current session token |
| `GET`  | `/auth/verify` | Validate JWT token (used for auth guards) |
| `POST` | `/auth/change-password` | Change password (requires valid token) |
| `POST` | `/auth/refresh` | **New**: Rotate short-lived JWT using long-lived refresh token |

---

## 📋 Cases

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/cases/draft` | Create new case draft (step 1) |
| `POST` | `/cases/{case_id}/participants` | Add participants to existing case |
| `POST` | `/cases/{case_id}/objectives` | Add objectives to case |
| `POST` | `/cases/{case_id}/assign` | Assign investigator to case |
| `GET`  | `/cases/{case_id}` | Get full case details (admin/client view) |
| `GET`  | `/cases/client-case/{case_id}` | Client-specific case view (minimal fields) |
| `POST` | `/cases/{case_id}/complete` | Mark case as complete |
| `POST` | `/cases/{case_id}/initiate-media-zip` | Start async ZIP generation for case media |
| `GET`  | `/cases/{case_id}/media-zip-request` | Poll ZIP status (`pending`/`completed`/`failed`) |
| `GET`  | `/cases/{case_id}/messages` | List messages for case |
| `POST` | `/cases/{case_id}/messages` | Send new message |
| `GET`  | `/cases/{case_id}/activities` | List all activities for case |
| `POST` | `/cases/{case_id}/activities` | Create new activity |
| `GET`  | `/cases/{case_id}/audit-logs` | Retrieve audit trail for case |

---

## 🖼️ Media

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/media/client-list.php?case_id=X` | List media visible to client |
| `GET`  | `/media/list.php?entry_id=X` | List media for specific entry |
| `POST` | `/media/upload` | Upload file to Bunny CDN (returns `bunny_path`) |
| `POST` | `/media/bulk-update` | Update metadata for multiple media items |
| `POST` | `/media/bulk-delete` | Delete multiple media items |
| `POST` | `/media/link_to_entry.php` | Link uploaded media to case entry |
| `GET`  | `/media/get_unlinked.php?case_id=X` | List media not yet linked to any entry |

---

## 👥 Participants & Objectives

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/cases/{case_id}/participants` | List all participants for case |
| `GET`  | `/cases/{case_id}/objectives` | List all objectives for case |
| `POST` | `/cases/{case_id}/objectives/batch` | Bulk-create objectives (array input) |

---

## 🛠️ Utilities

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/media/cleanup` | Remove orphaned media (no associated case/entry) |
| `POST` | `/media/sync` | Reconcile media records with Bunny CDN inventory |
| `GET`  | `/auth/check.php` | Health check endpoint (used by DOAP) |