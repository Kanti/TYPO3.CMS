..  include:: /Includes.rst.txt

..  _feature-110664-1793102200:

========================================================
Feature: #110664 - Share backend sessions across domains
========================================================

See :issue:`110664`

Description
===========

Backend logins now synchronize their session across the HTTPS origins configured
for the installation. Participating domains use the same internal session
identifier, so session-specific module data and authentication refer to the same
server-side session.

The local login, including any required multi-factor authentication, completes
before synchronization starts. The login request does not make requests to
other domains. Synchronization runs subsequently in the browser.

Target origins
--------------

Targets are collected automatically from the effective site bases, all language
bases including disabled languages, and the validated current origin. Only the
currently effective :yaml:`baseVariants` are considered. Duplicate origins are
combined; language paths do not become backend installation paths.

All participating origins must:

* Serve the same TYPO3 installation, using a shared backend session store and
  identical installation key material.
* Provide the synchronization endpoints over HTTPS under the same installation
  path and backend entry point.
* Be controlled and trusted as origins for backend authentication.
* Retain an origin-compatible referrer policy for backend pages, such as the
  default :literal:`strict-origin-when-cross-origin`. A
  :literal:`no-referrer` override can suppress the Origin header on form POSTs;
  these transfers are rejected.

These are operating requirements of the feature. Site configuration alone does
not establish that the required endpoints are reachable. No additional
project-specific synchronization configuration is required.

Authentication and existing sessions
------------------------------------

Each target receives a separately signed session cookie whose domain and path
scope match that target. The internal session identifier is shared; cookie
values can differ.

A valid existing session of the same backend user is replaced by the incoming
shared session. A valid session of a different backend user is preserved, and
that origin is reported as skipped. The target session is checked before a
cookie is changed.

Switching to another backend user and switching back synchronize the domains
participating in that shared session. The existing session identifier rotation
remains in place. Old identifiers and pending transfers for an earlier session
generation become invalid. Independent sessions of other users remain
unaffected.

Logging out invalidates the shared server-side session on all participating
domains and invalidates pending transfers. Cookies on other domains may remain
stored until that domain is visited, but they no longer authenticate the user.

Browser behavior
----------------

Synchronization first uses hidden frames where the browser permits the
existing :literal:`SameSite=Strict` cookies to be used. This is possible between
origins belonging to the same site, for example
:literal:`https://de.example.org` and :literal:`https://en.example.org`.

For independent sites, such as :literal:`https://example.de` and
:literal:`https://example.com`, synchronization uses top-level navigations.
A loaded target document performs a subsequent same-origin request so that an
existing Strict session cookie can be checked before any replacement. A visible
iframe would still be a cross-site context and does not remove this requirement.

Top-level navigation can be visible and can take time when a target server
responds slowly. This does not change the already completed local
authentication.
Automatic navigation is deferred once the user starts working in the backend
or a module frame has focus. The synchronization status then offers an explicit
action to complete sign-in on the remaining domains, avoiding an unexpected
navigation away from current work.

The feature preserves Strict cookies and the framing protection of ordinary
backend pages.

Security and incomplete synchronization
---------------------------------------

Transfers use opaque, short-lived codes bound to exactly one target origin
including scheme, host and port, the designated synchronization endpoint, the
initiating browser, and the current authenticated source session. Codes have a
fixed purpose and can be consumed atomically only once. A code issued for one
origin cannot be redeemed on another origin.

Neither the internal session identifier nor the session cookie token is exposed
in URLs or JavaScript. A transfer does not bypass authentication or multi-factor
authentication.
Synchronization state stores only a SHA-256 fingerprint of the session identifier
for rotation checks. Transfer payloads are encrypted and authenticated using
TYPO3's cipher service and a dedicated key derived from the installation key.
The ciphertext is bound to the transfer code, purpose, target origin and expiry;
the session identifier is not stored in plaintext alongside its hashed session
record.
Bridge pages use :literal:`Referrer-Policy: strict-origin`, exposing only the
origin on document transitions. Codes are transported in POST bodies.
Expired transfer records are removed automatically when new transfers start.

Synchronization attempts and retries are bounded. The backend reports
successful targets, targets skipped because another user is logged in, and
failed targets. The local login remains successful if some targets fail.
Retrying uses fresh codes and checks the existing target session again.

Impact
======

Backend users can share their login and session-specific settings across the
installation's configured domains without weakening the existing cookie scope
or SameSite protection. Existing independent sessions of other users are
preserved.

Installations must update the database schema and meet the target-origin
requirements above. Administrators should account for the necessary browser
navigations when independent sites participate and for incomplete
synchronization when targets are unavailable.

..  index:: Backend, JavaScript, Database
