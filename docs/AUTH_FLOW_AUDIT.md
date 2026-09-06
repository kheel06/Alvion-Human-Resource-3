# Authentication Flow Audit & Fix

## Root Cause Analysis

The `ERR_TOO_MANY_REDIRECTS` loop occurred due to a combination of:

1. **Session cookie path/domain mismatch** – Default PHP session cookies could fail to be sent on redirects when the request host differed from `BASE_URL`, or when cookie params were not explicit.

2. **Wrong `.htaccess` RewriteBase** – `RewriteBase /hospital-core1-system/` (from a different project) could cause path resolution issues.

3. **Absolute URL redirects** – Using `BASE_URL` or `$_SERVER['HTTP_HOST']` for redirects introduced cross-origin risk; path-only redirects are more reliable.

4. **No explicit session cookie configuration** – Missing `path`, `samesite`, and `httponly` could lead to inconsistent cookie behavior.

## Corrected Login Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  UNAUTHENTICATED USER                                                        │
└─────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────┐     GET /auth/employee-login.php
│  Login Page         │ ◄─────────────────────────────────── No redirect
│  (shows form)       │     Session: empty or no user_id
└─────────────────────┘
         │
         │  POST (credentials)
         ▼
┌─────────────────────┐     OTP required? → Show OTP modal
│  processPrimary     │     No OTP? → finalizeUserLogin()
│  LoginAttempt       │
└─────────────────────┘
         │
         │  OTP verified OR no OTP flow
         ▼
┌─────────────────────┐     session_regenerate_id(true)
│  finalizeUserLogin  │     Set $_SESSION[user_id, role_name, ...]
└─────────────────────┘
         │
         │  header('Location: /admin/admin-dashboard.php')  ← ONE redirect
         │  exit;
         ▼
┌─────────────────────┐     requireAuth() passes
│  Dashboard          │     checkRole() passes
│  (admin/employee)   │     Render page
└─────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  AUTHENTICATED USER visits /auth/employee-login.php (GET)                    │
└─────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────┐     user_id in session
│  Login Page         │     ref=forbidden? → Show form + error
│  initializeLogin    │     else → redirect to /{dashboard} (ONE redirect)
└─────────────────────┘
```

## Protected Page Flow

```
GET /admin/admin-dashboard.php
         │
         ▼
┌─────────────────────┐     No user_id? → Location: /auth/employee-login.php
│  requireAuth()      │     exit;
└─────────────────────┘
         │
         ▼
┌─────────────────────┐     Role not allowed? → Location: /auth/employee-login.php?ref=forbidden
│  checkRole([...])   │     exit;
└─────────────────────┘
         │
         ▼
  Render dashboard
```

## Changes Made

| File | Change |
|------|--------|
| `config/config.php` | `session_set_cookie_params()` before `session_start()` with `path=/`, `httponly`, `samesite=Lax` |
| `config/config.php` | `requireAuth()` and `checkRole()` use path-only redirects: `Location: /auth/employee-login.php` |
| `config/database.php` | Removed trailing `?>` to prevent accidental output before headers |
| `.htaccess` | `RewriteBase /` (was `/hospital-core1-system/`) |
| `index.php` | Path-only redirects for auth and dashboard |
| `auth/employee-login.php` | "Already logged in" redirect only on GET; path-only redirects; `session_regenerate_id(true)` after login |

## Best Practices Applied

1. **Always `exit` after `header('Location: ...')`** – Prevents further execution.
2. **Path-only redirects** – Use `Location: /path` instead of full URLs to stay on the same origin.
3. **Explicit session cookie params** – `path=/`, `httponly`, `samesite=Lax` for consistent behavior.
4. **`session_regenerate_id(true)` after login** – Reduces session fixation risk.
5. **No output before headers** – Removed trailing `?>` from included PHP files.
6. **GET-only "already logged in" redirect** – Avoids redirecting during POST (form submit).

## Testing

1. Clear cookies for `hospital-hr3-system.test`.
2. Visit `http://hospital-hr3-system.test/auth/employee-login.php`.
3. Log in with valid credentials.
4. You should be redirected once to the dashboard.
