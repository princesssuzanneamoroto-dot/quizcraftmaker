# QuizCraft

A small single-page quiz app with a PHP session-based auth/API layer.

## Requirements

- PHP 7.4+ (tested on 8.3) with `mysqli`
- MySQL 5.7+ / MariaDB 10.2+
- A web server (WAMP works)

## Setup

1. Create a MySQL database named `quizcraft`.
2. Import [`setup.sql`](setup.sql) into it (phpMyAdmin or `mysql -u root -p quizcraft < setup.sql`).
3. Make sure `api/db_connect.php` can connect. It uses these environment variables, with local WAMP defaults:

   | Variable | Default |
   | --- | --- |
   | `QUIZCRAFT_DB_HOST` | `localhost` |
   | `QUIZCRAFT_DB_USER` | `root` |
   | `QUIZCRAFT_DB_PASS` | *(empty)* |
   | `QUIZCRAFT_DB_NAME` | `quizcraft` |

   For hosted MySQL (e.g. InfinityFree), set the exact hostname shown by the host, such as `sql123.infinityfree.com` — not a bare domain. With Apache you can set these in the virtual host or in `api/db_connect.php` if your host does not expose env vars.
4. Serve the project folder so `index.html` and `api/` share the same origin. The app uses a `SameSite=Lax`, `HttpOnly` session cookie; cross-origin front ends are intentionally not supported.

## What is stored where

- `users`, `user_settings`, `feedback` — accounts, preferences, feedback (`api/account.php`).
- `classes`, `class_members`, `quizzes`, `materials`, `submissions`, `user_state` — classroom data (`api/data.php`, `api/classes.php`, `api/submissions.php`).
- The browser keeps a copy of classroom data in `localStorage` for a responsive UI; the PHP/MySQL tables are the durable source of truth and are synced on login and after changes.

The server determines the signed-in account and role from the session on every request. Roles are never read from browser storage for API authorization.

## Notes

- Google/Facebook buttons are intentionally disabled: enabling them requires a server endpoint that verifies the provider token and looks the role up in the database.
- `api/me.php`, `api/login.php`, `api/signup.php`, `api/logout.php` are the auth endpoints; `api/teacher_area.php` and `api/student_area.php` were removed after testing.
