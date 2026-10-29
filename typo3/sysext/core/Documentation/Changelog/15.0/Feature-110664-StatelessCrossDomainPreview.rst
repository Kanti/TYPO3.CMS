.. include:: /Includes.rst.txt

.. _feature-110664-1793200800:

=================================================
Feature: #110664 - Stateless cross-domain preview
=================================================

See :issue:`110664`

Description
===========

Backend page preview links and the Preview module use a signed :code:`_ps`
JWT to preview authorized sites of the same installation without requiring
backend cookies on their frontend domains. The token expires after fifteen
minutes. An authenticated backend window renews it one minute before expiry.
Switching tabs reuses the token while it remains valid.

The View button opens a standalone backend preview with a top bar and an
iframe. It offers reload and opening the current live page, and remains
usable after the originating backend tab closes. No simulation controls
are added.

The token contains the workspace, simulated UTC timestamp, frontend groups,
visibility flags, authorized sites and languages, and page mounts. The
frontend checks its signature, expiry and scope and rechecks the issuer's
current permissions. It creates no backend login or preview session.
Simulated groups replace frontend login groups. The infrastructure supports
hidden pages, hidden content, scheduled records and disabled languages;
the Preview module adds no simulation controls.

Frontend authentication uses the existing session API with an anonymous,
non-persisted session object. Session data and flash messages are available
within the current request. Preview requests neither load nor change an
existing frontend login, persist session data, nor issue frontend session
cookies. Selected groups use the same simulated user identity as the Admin
Panel. Normal requests retain the existing session behavior.

Preview context is applied before page routing. Preview responses bypass
frontend page caches and send :http:`Cache-Control: private, no-store`,
:http:`Referrer-Policy: no-referrer` and :http:`X-Robots-Tag: noindex, nofollow`.
Internal page links and eligible redirects retain the token. External
destinations receive no token.

The frontend and authenticated backend communicate using origin- and
window-checked messages. Cross-domain iframe navigation is performed by
the parent. Both the standalone preview and Preview module keep the current
frontend address in the backend URL so reload and browser history restore
the current page. Token updates and same-document history changes do not
reload the iframe. The
Navigation API is supported, with link and form handling as a fallback.
No iframe sandbox is introduced.

Impact
======

A valid :code:`_ps` takes precedence over backend cookies and legacy
preview parameters. An invalid token returns HTTP 403 instead of falling
back to a public page. Requests without :code:`_ps` retain the existing
preview and Admin Panel behavior.

Tokens are bearer credentials. Stateless tokens remain usable until their
expiry after backend logout; further renewal requires backend authentication.
Changing the installation encryption key invalidates all tokens. Removing
issuer permissions takes effect on the next frontend request.

Web server or proxy framing headers can still prevent embedding. Sites
must permit the backend to frame their frontend. JavaScript requests that
omit :code:`_ps` use the ordinary frontend context. In browsers without the
Navigation API, programmatic document navigation also needs to retain the
token. Share links, Visual Editor integration and Admin Panel migration are
outside this change.

.. index:: Backend, Frontend, ext:backend, ext:frontend, ext:viewpage
