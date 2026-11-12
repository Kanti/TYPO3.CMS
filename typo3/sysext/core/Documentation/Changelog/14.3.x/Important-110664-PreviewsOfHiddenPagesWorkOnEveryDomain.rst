..  include:: /Includes.rst.txt

..  _important-110664-1794224719:

==================================================================
Important: #110664 - Previews of hidden pages work on every domain
==================================================================

See :issue:`110664`

Description
===========

Editors preview hidden, scheduled, access-restricted and workspace pages of
every site of an installation, whatever domain they logged in to the backend
on. Where the backend login cookie does not reach the site, the preview uses a
**preview token** instead of a backend login, so a shared
:php:`$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieDomain']` is no longer needed
for the previews.

Where previews use a preview token
----------------------------------

..  list-table::
    :header-rows: 1

    *   -   Entry point
        -   Preview token
    *   -   :guilabel:`View` button and context menu
        -   where needed
    *   -   :guilabel:`Save and view`
        -   where needed
    *   -   Live search
        -   where needed
    *   -   Dashboard widget of the latest changed pages
        -   where needed
    *   -   Preview buttons of the workspace module
        -   where needed
    *   -   Module :guilabel:`Content > Preview`
        -   always
    *   -   Workspace preview
        -   always; the live frame gets a visitor token

**Where needed** means: the backend login cookie does not reach the host of
the site, because it is another host than the one of the backend and not
covered by the cookie domain, and the page needs the backend login to be shown
as a preview. A page needs the backend login if

*   it is hidden, or below a hidden page that extends to its subpages,
*   it is a backend user section, or below one,
*   its language is disabled in the site configuration,
*   it is shown in a workspace,
*   its preview simulates a date or a frontend user group because of its
    publication dates or access restrictions, or
*   the previewed record is hidden or not published at the moment.

There these entry points open the preview page of the backend, which shows
the site in a frame over the whole window and keeps the address of the frame
in its own address for a reload. Everywhere else they open the page directly,
as before.

The module :guilabel:`Content > Preview` and the workspace preview always use
a token, so links, redirects and forms stay in their frames. An address
outside the sites of the installation, such as one set by an event listener,
is shown without a token. If the editor may not preview a page, the backend
shows a message instead.

PHP API
-------

*   :php:`\TYPO3\CMS\Backend\Routing\PreviewUriBuilder::buildUri()` still
    returns the address of the page on the site.
*   The new internal method :php:`PreviewUriBuilder::buildBackendPreviewUri()`
    returns the preview page where a preview token is needed. The buttons of
    the backend use it.
*   :php:`PreviewUriBuilder::buildDispatcherDataAttributes()` and the other
    methods that build the attributes of a preview button follow the same
    rule, also in extensions.

Preview tokens
--------------

The backend checks the rights of the editor (web mounts, page permission,
workspace and languages) and signs a preview token: a JSON Web Token with its
own key derived from the encryption key. It travels in the query parameter
:code:`__t3ct`:

..  code-block:: text
    :caption: The address of a page in the frame of a preview

    https://www.example.org/news?__t3ct=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...

The token

*   names the editor,
*   holds the simulation of the page: the workspace, the date and the frontend
    user groups of an access-restricted page,
*   names no hosts, so its size does not grow with the number of sites,
*   is bound to the backend that signed it and to the browser it was signed
    for,
*   is valid for 15 minutes; the backend page around the frame renews it
    before it expires. Clocks of backend and site servers may differ by up to
    60 seconds.

What the site checks
--------------------

On every request with a token the site checks the token and the rights of the
editor as they are now. The editor then becomes the backend user of the
request, stateless: no session is created and no cookie is set.

..  list-table::
    :header-rows: 1

    *   -   Check
        -   Message when it fails
    *   -   Signature and claims of the token
        -   *This preview is not valid.*
    *   -   Expiry of the token
        -   *This preview has expired.*
    *   -   The browser the token was signed for
        -   *This preview belongs to another browser.*
    *   -   The request is the page in the frame
            (:http:`Sec-Fetch-Dest: iframe`) or one this page sends to its own
            origin
        -   *This preview is only available in the backend.*
    *   -   The host belongs to a site the editor may preview now: web mounts
            and languages; a base without a host counts on the host of the
            backend
        -   *This preview is not valid.*
    *   -   The editor exists, is enabled and may log in: backend lock,
            IP mask, :php:`$GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL']`
        -   *Your backend account may not see this preview.*
    *   -   The editor may enter the workspace of the token
        -   *Your backend account may not see this preview.*
    *   -   The site, the permission to show the page and the language
        -   *This page is not part of the preview.*

A frontend user group the editor may not read is only simulated on pages whose
access restrictions name it. When every check passes, the page is shown as for
a backend login. Every renewal checks the rights of the editor and the hosts
of the preview again.

..  important::

    The backend user of a preview has no write rights: it may not change
    tables, pages or files, and the DataHandler throws an exception instead of
    changing a record or running a command for it, also for an administrator.
    Code that passes a backend user of its own to the DataHandler is not
    affected.

Live frame of the workspace preview
-----------------------------------

The live frame next to a workspace preview gets a **visitor token**. It shows
the page as a visitor sees it now: live, without hidden records, at the current
time and without a frontend login, also for an editor without access to the
live workspace. A page that is hidden in the live workspace therefore shows as
not found there. A visitor token that
asks for more is refused. It is valid on the sites the editor who opened the
preview may preview now, but this editor does not become the backend user of
the request.

Links, redirects and forms
--------------------------

*   Links rendered by TYPO3 and redirects of the site, of languages, of
    shortcuts and of :composer:`typo3/cms-redirects` keep the token for the
    hosts of the preview, never for any other host. Only the token is added or
    removed; the rest of the address keeps its bytes, and a redirect to
    another host without a token is left as it is.
*   Redirects of :composer:`typo3/cms-redirects` that respect query parameters
    ignore the token.
*   A small script keeps the token in navigations started by scripts and asks
    the backend for a token when a navigation leads to another host of the
    preview.
*   When the backend renews the token, the script puts the new one into the
    links, the form actions and the token fields of the page. An address a
    script of the site keeps elsewhere, for example in a data attribute for
    fetch or XHR requests, keeps the token it was rendered with and is refused
    once that token expired.
*   Forms rendered with :html:`<f:form>` stay in the frame: a target
    :html:`_top` or :html:`_parent`, as the login form of
    :composer:`typo3/cms-felogin` uses, becomes :html:`_self`, and a form sent
    via HTTP GET to a host of the preview carries the token in a hidden
    field.
*   Other forms get no token of their own: a form sent via HTTP POST without
    the token in its action shows the page without the preview.

The script runs under the Content-Security-Policy of the site: it carries the
nonce of the request, and where the site uses hash values
(:yaml:`behavior.useHash`), its hash is added to the policy and the script
carries it as :html:`integrity`.

Admin panel
-----------

The admin panel is shown in the frame and stores its settings through the
backend page around the frame. Actions of the admin panel that call the
backend directly, such as clearing the page cache, are not available there.

Impact
======

Requests
--------

*   A request with a valid preview token never builds a backend user from the
    backend login cookie and ignores :code:`ADMCMD_prev`,
    :code:`ADMCMD_simTime` and :code:`ADMCMD_simUser`.
*   A request with an invalid or expired token, or of an editor who may not
    see the page, answers with status 403 and the reason; it never falls back
    to the live page.
*   A preview token is accepted for the page in the frame and for the requests
    this page sends itself to its own origin
    (:http:`Sec-Fetch-Site: same-origin`), such as fetch, XHR and elements on
    pages of TYPO3; requests from anywhere else are refused.
*   A window or tab opened with a token, such as a link opened in a new tab or
    a target :html:`_blank` or :html:`_top`, is redirected to the same address
    without the token.
*   Requests without :code:`__t3ct` behave as before.

In a frame on another domain than the backend, browsers block the cookies of a
frontend login, so logging in to the site there is not possible.

Caching
-------

:code:`__t3ct` is never part of the cHash calculation, whatever
:php:`$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters']`
holds.

Responses to a request with a preview token are never cached, as links and
forms of the page carry the token; the module :guilabel:`Content > Preview`
therefore renders every page without the page cache. The responses are sent
with :http:`Cache-Control: private, no-store`, :http:`X-Robots-Tag: noindex`
and :http:`Referrer-Policy: no-referrer`, and their :http:`frame-ancestors`
directive allows only the backend that signed the token.

Server configuration
--------------------

..  list-table::
    :header-rows: 1

    *   -   Part of the setup
        -   Requirement
    *   -   Shared cache or CDN
        -   Must not ignore the query string for requests with :code:`__t3ct`.
    *   -   Reverse proxy or cache
        -   Must pass the :http:`User-Agent` and :http:`Sec-Fetch-*` headers
            unchanged for requests with :code:`__t3ct`. A rewritten
            :http:`User-Agent`, for example a device class, makes every token
            look like one of another browser; dropped :http:`Sec-Fetch-*`
            headers make an HTTPS site refuse every token.
    *   -   :http:`Content-Security-Policy` of the web server
        -   A :http:`frame-ancestors` directive must allow the backend origin,
            otherwise a frame on another origin stays empty. A
            :http:`script-src` that allows scripts only by nonce or hash blocks
            the script of the preview and the script of its error page:
            navigations started by scripts then lose the token, and an expired
            token is not renewed from the error page.
    *   -   :http:`X-Frame-Options` of the web server
        -   Does not matter: browsers ignore it next to the
            :http:`frame-ancestors` directive of the preview.
    *   -   Sites with a plain HTTP base
        -   Browsers send :http:`Sec-Fetch-Dest` only to HTTPS sites and to
            :code:`localhost`. Without it the token is accepted only if
            :php:`$GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL']` is disabled.

..  note::

    A token ends at its expiry; logging out of the backend does not revoke
    tokens already handed out, but a disabled editor can no longer use them.

..  index:: Backend, Frontend, ext:backend, ext:frontend, ext:workspaces
