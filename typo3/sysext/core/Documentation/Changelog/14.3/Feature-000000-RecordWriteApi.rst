.. include:: /Includes.rst.txt

.. _feature-000000-1792300000:
.. _record-write-api:

===============================================
Feature: #000000 - Experimental record write API
===============================================

See :issue:`000000`

Description
===========

Mutable :php:`RecordDraft` objects and the injectable
:php:`TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface` complement
the immutable Record API with TCA record writes through DataHandler. The API
supports backend, frontend and CLI execution with explicit identities.

Status and scope
----------------

The classes in :php:`TYPO3\CMS\Core\Domain\Persistence` provide an
experimental write API for TCA records. They have the same internal
stability status as :php:`RecordFactory`; they are not a stable extension API.
Existing :php:`RecordInterface`, :php:`Record`, :php:`RawRecord` and their
read-side implementations are unchanged.

All persistent record and relation mutations run through :php:`DataHandler`.
Its permissions, TCA evaluation, hooks, history, reference-index updates,
localization, workspace versioning and cache handling remain active. This API
neither uses Extbase persistence nor introduces another persistence framework.

The existing :php:`DataHandler` class remains unchanged. A dedicated, non-shared
internal subclass, :php:`RecordWriteDataHandler`, calls its protected creation
defaults (including Page/User-TSconfig) and table ACL with their existing hooks.
It observes native insert/update success notifications through :php:`log()`;
it does not parse log messages or require stored :sql:`sys_log` rows. Persistence,
authorization, default calculation and logging stay in the parent implementation.
The existing :php:`Channel` attribute preserves the native DataHandler log channel.
An empty error log alone does not confirm a write.

The adapter rejects DataHandler XCLASSes, replacement service classes and
preconfigured public DataHandler state before executing a write. Standard
DataHandler hooks and events remain active. Custom dependency wiring
specifically for the base DataHandler service is not inherited by the dedicated
service; installations using that configuration need an explicit integration
before using this experimental API.

Execution identities and dependency injection
--------------------------------------------

Inject :php:`TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface`.
Core service configuration aliases this contract to :php:`RecordWriter`, so
controllers and services can use constructor injection and mock the contract in
unit tests.

.. code-block:: php

   use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;

   final readonly class ContentService
   {
       public function __construct(private RecordWriterInterface $writer) {}
   }

The requesting visitor and the write execution identity are separate:

.. list-table:: Default execution identities
   :header-rows: 1

   * - Execution context
     - Write identity
     - Workspace
   * - Backend request
     - Current authenticated Backend User, with their actual permissions
     - Backend User and Context must agree
   * - Frontend request, including BE preview or an authenticated FE User
     - ``_cli_`` system user, provisioned on first use; any editor session is ignored
     - Live, independent of an editor's preview workspace
   * - CLI or Scheduler with established CommandLineUserAuthentication
     - Existing CLI identity
     - Its workspace and Context must agree
   * - CLI without an established CLI identity
     - ``_cli_`` system user, provisioned on first use
     - Live; a conflicting Context is rejected

The sessionless system identity loads the enabled ``_cli_`` user's real record,
groups, permissions and TSconfig using :php:`BackendUserAuthentication`. When the
reserved identity does not exist, it is provisioned automatically through the
existing :php:`CommandLineUserCreation::ensureCliUserExists()` mechanism, using
the same default administrator configuration and random password as native CLI
authentication. No prior CLI command or manual setup is required.

Provisioning retains existing Core semantics without changing native CLI
behavior. Disabled, expired or otherwise unavailable active accounts fail with
:php:`InvalidWriteContextException`; their permissions are never elevated and
there is no fallback to an editor session. Core ignores soft-deleted accounts
when deciding whether to provision a new default identity; deleted rows are not
reactivated or changed. Maintenance restrictions remain active.

The API adds no global provisioning lock or concurrency guarantee. Concurrent
first-use provisioning inherits the existing Core limitation; hardening that
bootstrap mechanism is separate work. The existing CLI-user bootstrap is the
only special identity write here; ordinary records and relations use DataHandler.

Each execution passes its identity explicitly to a fresh DataHandler. Legacy
Core dependencies and hooks also require a temporary global backend context;
the writer scopes that context and restores authentication globals and Context
aspects in a ``finally`` block. History and logs attribute system writes to the
``_cli_`` user. There is no frontend session, user impersonation or CLI-mode spoof.
The selected authentication object is the sole authority passed to DataHandler.
The ``backend.user`` Context aspect is synchronized for legacy readers and
restored afterward; a different previous aspect neither authorizes nor denies
a write. Workspace, language and request-lifetime checks remain active.

The API flushes the in-memory runtime cache when switching identities and after
an execution. This prevents stale layouts and workspace preview data without
changing BackendUtility or BackendLayoutView cache behavior. It does not flush
persistent caches merely to switch identities.

**The system identity does not authorize an HTTP request.** Extension controllers
must enforce application permissions, request-method/CSRF or equivalent request
authorization, and input validation. They must choose allowed tables, storage
pages and fields on the server. Do not pass arbitrary visitor input to this API.
Neither a frontend login nor a backend session automatically authorizes a public
frontend business operation.

Reading is independent of write permission. :php:`edit()`, :php:`editByUid()`,
:php:`RecordDraft::get()` and relation reads do not require edit access, including
excluded or read-only fields, locked records, restricted pages or languages.
Structural identity checks still reject ambiguous overlays, foreign workspace
versions and invalid records. Extensions must enforce their own application
read-access policy when exposing record values to visitors.

:php:`save()` and batch preflight enforce write permissions. Existing-record
checks reuse DataHandler's table/page/update checks and
:php:`BackendUserAuthentication::checkRecordEditAccess()` for language, authMode,
edit locks and registered hooks. Excluded-field permissions and read-only TCA
policies are checked when normalizing changes. DataHandler remains responsible
for complete write-time access checks; the API does not introduce another
permission system. No-op saves still require edit permission.

Create and update
-----------------

.. code-block:: php

   $draft = $recordWriter->create('tt_content', 42);
   $draft->setValues([
       'CType' => 'text',
       'header' => 'New title',
       'bodytext' => '<p>Content</p>',
       'hidden' => false,
       'colPos' => 0, // Must exist in this page's backend layout.
   ]);
   $record = $recordWriter->save($draft);

   $edit = $recordWriter->edit($record);
   $edit->set('header', 'Updated title');
   $updated = $recordWriter->save($edit);
   // $record is unchanged. $updated is a freshly loaded Record API object.

   $edit = $recordWriter->editByUid('tt_content', 123);
   $edit->set('header', 'Another title');
   $updated = $recordWriter->save($edit);

Creation always requests the default language. Use :php:`localize()` or
:php:`copyToLanguage()` for other languages. The language is not inherited from
frontend fallback data or user TSconfig defaults.

Draft lifecycle
---------------

Creating or changing a draft does not persist anything. Only explicitly assigned
editable fields enter the datamap; creation and semantic relation operations add
their required metadata and child updates. Repeated :php:`set()` replaces the pending value.
:php:`setValues()` is equivalent to repeated assignments.

:php:`get()` returns the pending value when assigned and the original snapshot
otherwise, using consistent PHP representations: JSON becomes PHP arrays/scalars,
datetime becomes :php:`DateTimeImmutable` (or the read transformer's empty value),
links become :php:`TypolinkParameter`, a single checkbox becomes a boolean and
multiple checkboxes remain an integer bitmask. Relations are ordered lists of
:php:`RecordReference` objects, with new unsaved related drafts retained as drafts.
Raw persistence values remain internal; drafts never return filtered frontend
collections as authoritative relation data. Other original values use the Core
Record API's field transformer.

No defaults are synthesized by :php:`get()`. A missing value on a new draft raises
:php:`InvalidRecordValueException`; explicit :php:`null` remains distinguishable
from a missing assignment. Pending values still need save/preflight validation.

.. code-block:: php

   $edit->set('subtitle', null); // Only if the effective field is nullable.
   $edit->discard('subtitle');  // Restore the original value, or remove assignment.
   $pending = $edit->hasChanges();

Validation errors before execution leave drafts editable. Once an execution is
attempted, all participating drafts are consumed, whether it succeeds or fails.
No-op saves also consume the draft. Saving or modifying a consumed draft raises
:php:`InvalidWriteContextException`. Its pending values remain available for
diagnostics; create a fresh draft from a reloaded record to make another change.

For ordinary scalar fields, equal current database values are omitted before
execution. No-op saves still check the backend user's record write permissions. A batch containing only no-op updates does not execute DataHandler.
Relations use their actual members, not their stored count, for post-write checks.

Field handling
--------------

Field availability and configuration come from :php:`TcaSchemaFactory`.
Changing a type discriminator, such as :php:`CType`, validates all pending fields
against the resulting sub-schema, including column overrides. Current stored
types and synchronization states are rechecked before execution. Creation reuses
DataHandler defaults, including Page/User-TSconfig, to determine the effective type.
Foreign-table type discriminators are currently unsupported.

Supported write-side PHP values include:

* Input, text, email, color, slug and password: strings. Emails are checked for
  valid syntax. DataHandler still performs its configured evaluation.
* Numbers: integers, or finite floats for decimal fields; configured ranges apply.
* Check fields: booleans for a single checkbox, integers for checkbox bitmasks.
* Datetime: :php:`DateTimeInterface` or the existing DataHandler integer
  representation. DataHandler performs date/time and database-format conversion.
* Link: a string or :php:`TypolinkParameter`, encoded by the existing codec.
* JSON: PHP arrays and scalar JSON values. A PHP string is a JSON string value,
  not pre-encoded JSON. Explicit null is accepted only for nullable fields.
* Select and radio: a string/integer or a list of those values; a single
  select requires exactly one value. Declared items are validated. Dynamic ``itemsProcFunc`` and ``itemsProcessors``
  use the Core ItemProcessingService with the prospective row, effective schema,
  storage page, TSconfig and site. In particular, ``tt_content.colPos`` follows
  the selected backend layout; absent columns are rejected. Existing drafts
  use the record's current effective PID, row identity, language and site, even
  when another operation moved the record after draft creation. New drafts use
  their intended logical storage page. This does not
  reproduce every FormEngine UI constraint or authorize arbitrary item callbacks.
* Nullable fields: explicit :php:`null`, only where the schema allows it.

UID, PID, timestamps, creation date, deletion and sorting fields, language
identity, synchronization metadata and :php:`t3ver_*` are protected.
Use semantic methods for moving, deleting and localizing. Editable enable columns
such as hidden/starttime/endtime remain supported. Read-only, passthrough and
none fields cannot be assigned directly. Excluded fields require the corresponding
backend permission. Synchronized or language-excluded fields cannot be overridden
on a translation.

FlexForms, folder fields, user-defined field types and transformed read-side
collections are not accepted as write values. Supply supported primitive PHP
values instead of serializing an entire resolved record.

Relations
---------

.. code-block:: php

   use TYPO3\CMS\Core\Domain\Persistence\RecordReference;

   $categoryEdit = $recordWriter->editByUid('sys_category', 7);
   $categoryEdit->setRelation('parent', $parentCategory);
   $recordWriter->save($categoryEdit);

   $edit = $recordWriter->editByUid('tt_content', 123);
   $edit->relations('categories')->add(new RecordReference('sys_category', 7));
   $edit->relations('categories')->remove($otherCategory);
   $edit->relations('categories')->setMembers([$categoryA, $categoryB]);
   $edit->relations('categories')->reorder([$categoryB, $categoryA]);
   $record = $recordWriter->save($edit);

   $edit = $recordWriter->edit($record);
   $edit->relations('categories')->clear();
   $record = $recordWriter->save($edit);

The field must be a compatible TCA relation. A :php:`RecordInterface` must carry
sufficient raw identity information. :php:`RecordReference` provides an explicit
existing table/UID reference; untyped integer relation IDs are rejected.

:php:`add()` and :php:`remove()` preserve operation intent. Immediately before
execution they are applied, in order, to the current unfiltered backend
membership. An addition does not discard unrelated additions by another draft;
a removal only removes its requested member. This also applies when the relation
set was already loaded before another write. Idempotent calls retain their
intent: ``add($member)`` still requests membership even when its preview already
contains that member.

:php:`setMembers()` intentionally supplies the **complete** ordered membership,
including removal of members added after the draft was created. This is an
explicit replacement, not a safe merge; :php:`clear()` is an empty replacement.
A new ``setMembers()`` cancels earlier pending relation operations.
:php:`setRelation()` and directly assigned relation lists also express replacement.

:php:`reorder()` requires exactly the same membership both when called and again
when compiled against current data. A changed member set causes a preflight
error and leaves the draft editable. For example, ``reorder([$categoryB])`` fails
if A and B are current members, whereas ``setMembers([$categoryB])`` explicitly
removes A. TCA cardinality, ownership, language and workspace rules apply to the
resulting membership. A merge that would exceed a single-reference field's
cardinality fails instead of overwriting another reference.

The resolved changes and observed membership are frozen for normalization,
no-op detection, inline detachment and post-write verification. Verification does
not reapply operations after saving. IRRE ordering can legitimately update only
child rows; such relation-only operations are verified against actual saved
membership and order even if the parent's count did not require an update.

Rebasing happens before DataHandler executes and is **not atomic optimistic
locking**. Simultaneous writes between observation and persistence can still race.
Applications requiring serialized writes need suitable application-level
coordination; hooks and other writers are not automatically locked by this API.

Existing members are loaded through :php:`RelationHandler` in backend context,
without frontend enable-column filtering. An incomplete frontend collection is
never used as the authoritative existing relation list. Saved membership and
ordering are checked again after execution.

Supported relations are single references, ordinary select/group relations,
MM relations and standard category reverse-MM relations. Live foreign-field IRRE
children can be attached, detached and reordered with ownership checks.
Detachment submits an explicit child datamap update and does not delete the
child. Child write permissions use existing backend/DataHandler checks.
Reparenting an owned child, inline cycles and multiple batch owners are rejected.

Workspace IRRE writes require versioned child tables and deliberately follow
the unchanged Core RelationHandler.
New workspace-owned children can be created in a batch, attached, detached and
reordered. Existing Live children may be retained when versioning their parent.
Adding or detaching an existing Live child's identity in a workspace is rejected
before execution, even if the child already has a workspace version. Core's
foreign-field resolver does not reliably reflect independently changed workspace
ownership: it can retain detached children or omit attached versions. Repairing
that global behavior requires a separate Core change.

The API also rejects pre-existing workspace IRRE data whose effective ownership
and Core-resolved membership disagree, instead of returning incomplete or
incorrect membership. This restriction does not affect Live IRRE or workspace
MM, select and group relations. Core copy/localization commands retain their
native recursive behavior.

Arbitrary inverse-MM configurations, duplicate-member relations, inline relations
without a foreign field, symmetric or foreign-selector/table/match-discriminator
relations, dynamic foreign-record item filters and TCA file fields are currently
rejected. Dynamic non-relational select fields, such as ``colPos``, are supported;
foreign item filters require additional FormEngine relation-item processing. These require additional ownership
and versioning rules.
FAL file-reference metadata and physical file operations are separate concerns;
this API does not upload, move or delete physical files.

Commands
--------

.. code-block:: php

   use TYPO3\CMS\Core\Domain\Persistence\RecordPosition;

   $moved = $recordWriter->move($record, RecordPosition::onPage(42));
   $moved = $recordWriter->move($moved, RecordPosition::after($otherRecord));
   $copy = $recordWriter->copy($moved, RecordPosition::onPage(43));
   $translation = $recordWriter->localize($moved, languageId: 1);
   $independent = $recordWriter->copyToLanguage($otherRecord, languageId: 1);
   $recordWriter->delete($copy);
   $restored = $recordWriter->restore('tt_content', $copy->getUid());

Page positions require a default-language storage page. A physical version UID
from the current workspace is resolved to its logical storage-page UID; translated
page IDs are rejected instead of implicitly switching languages.
Move-after requires another sortable record of the same table and language.
Copy/localize use DataHandler's result mappings and retain its native recursive
relation handling. Localization requires a default-language source, a positive
language ID and an available site language; missing translations are never
created implicitly by :php:`edit()`.

Restore is restricted to existing soft-deleted Live records. Workspace discard
is explicit and uses DataHandler's discard command:

.. code-block:: php

   $recordWriter->discardWorkspaceVersion($workspaceRecord);

Discard can remove workspace-owned children as prescribed by DataHandler.
Publishing a workspace remains a separate workflow.

Identity, language and workspaces
--------------------------------

A logical live identity, translated UID and physical workspace UID are distinct.
The resolver reads the actual row, validates overlay metadata, retains the
translation's logical identity and lets DataHandler create workspace versions.
Backend language permissions remain active.

The existing Record API is unchanged: returned records use the **physical saved
UID**. Use the writer's explicit identity metadata for links or a subsequent
controller action:

.. code-block:: php

   $identity = $recordWriter->identity($saved);
   $uidForNextAction = $identity->logicalUid;
   $databaseUid = $identity->physicalUid;
   $languageId = $identity->languageId;
   $workspaceId = $identity->workspaceId;
   $defaultLanguageUid = $identity->defaultLanguageUid;

The logical UID identifies the concrete language record, not automatically its
default-language origin. It stays stable when DataHandler versions an existing
record. A new workspace record has no Live origin yet; matching logical and
physical UIDs do not make it a published record. A controller must preserve and
validate its execution workspace when using a logical UID in a following action.
The functional backend example uses ``logicalUid`` in its follow-up request and
updates the same workspace version while leaving Live untouched.

Results have a fresh Record API identity map and use a captured language/workspace
context, including lazy field transformations after the caller's preview context
has been restored. They do not reuse old frontend overlays.

A frontend language fallback, missing raw identity, conflicting overlay metadata,
a record from another workspace, or a change of backend user/language/workspace
between draft creation and execution causes an explicit error. A Live identity
passed to :php:`edit()` is resolved in the selected backend workspace. For an
explicit physical identity, :php:`editByUid()` still rejects other workspaces.

Non-versioned tables and tables configured for unconditional Live editing are
rejected in a non-Live workspace. The workspaces extension must be loaded there.

Batch writes and new references
------------------------------

.. code-block:: php

   $batch = $recordWriter->batch();
   $parent = $batch->create('tx_example_parent', 42)->set('title', 'Parent');
   $child = $batch->create('tx_example_child', 42)->set('title', 'Child');
   $parent->relations('children')->add($child);
   $edit = $batch->editByUid('tt_content', 123)->set('header', 'Batch update');
   $result = $batch->execute();
   $parentRecord = $result->recordFor($parent);
   $childRecord = $result->recordFor($child);
   $updated = $result->recordFor($edit);

Compatible create/update drafts use one DataHandler datamap with its native
:php:`NEW...` reference remapping. A new related draft must belong to that batch.
All drafts are normalized before execution; conflicting logical targets and
conflicting child detachments are rejected. Commands are intentionally separate
from draft batches because a cmdmap cannot represent an arbitrary sequence of
commands on one record.

Execution context and batch cost
--------------------------------

A batch resolves its write context when its first draft is created. It shares
that captured identity and storage-position resolutions across compatible drafts,
then reloads and checks system permissions once per validation/execution boundary.
An empty batch neither initializes nor provisions a system identity.

The writer caches authentication prototypes with weak request-object keys and
clones them for independent drafts/batches. It does not retain a global system
user across requests. CLI operations without a request do not use that cache.
Backend identity handling remains native. Request, identity, language and
workspace changes are checked without repeatedly initializing authentication;
relevant system permission changes are revalidated before execution. Drafts from
another request are rejected, including reads of their original values.

Nested operations reuse an already-bound context while retaining ``finally``
restoration. The runtime cache is flushed on actual identity switches and after
DataHandler execution. New-record construction avoids
unnecessary global context switches; batch storage pages are resolved once per
position and checked again at execution.

Functional benchmarks cover 1, 100, 500 and 1,000 records and record initialization
counts, user/all SQL query counts, single-context initialization time, construction
time and total duration. CI asserts bounded authentication counts, not wall-clock
performance. A normal create-only batch requires two full system initializations
(first capture and execution validation), independent of its size; an additional
explicit ``validate()`` introduces one further boundary check.

Preflight and reconciliation
----------------------------

:php:`RecordWriteBatch::validate()` returns per-draft diagnostics without executing
DataHandler or consuming drafts. It checks values, effective schemas, existing
record write access, references, cycles and conflicts between otherwise valid drafts.
An importer can correct a draft or :php:`remove()` an invalid row from the batch.
Validation is a best-effort preflight, not a reservation, a dry-run of every hook
or a guarantee that DataHandler will accept the write. Create permissions and all
write-time DataHandler checks still apply during execution.

.. code-block:: php

   $validation = $batch->validate();
   foreach ($validation->all() as $entry) {
       if ($entry->errors !== []) {
           // Associate the retained draft with its source row and report errors.
           $batch->remove($entry->draft);
       }
   }
   $result = $batch->execute();
   $entry = $result->diagnostics->forDraft($parent);

Successful results and persistence exceptions expose :php:`RecordWriteDiagnostics`
with a retained draft, outcome, optional :php:`RecordIdentity`, optional freshly
verified record and error messages. Outcomes deliberately distinguish:

* ``notAttempted``: preflight only; this validation did not persist a record.
* ``confirmed``: a fresh record and its requested identity/relations were verified.
* ``written``: the dedicated DataHandler received a native insert/update success
  notification and the physical identity could be resolved, but complete
  postconditions have not been confirmed.
* ``unknown``: the outcome cannot be established. This also covers rows with no
  acknowledgement after an interrupted batch; absence of acknowledgement does
  not prove that hooks or nested DataHandlers made no persistent changes.

For datamap inserts/updates, Core emits success notifications after SQL and
history processing. If history or logging throws after SQL succeeds, the
outcome is conservatively ``unknown``
even when a physical UID can be recovered. An insert may also succeed before a
UID mapping becomes available. Nested native DataHandlers do not contribute
acknowledgements to the dedicated instance. These limitations reduce diagnostic
certainty, not the native execution of writes. Successful command operations
still require their explicit postconditions to be verified.

No outcome means an automatic rollback. Retain an application's import-row key
alongside each draft and reconcile any ``written`` or ``unknown`` rows before
retrying. Missing result mappings can leave a persisted record without a known
UID; do not automatically create a replacement.

Real extension controllers
--------------------------

The functional fixture extension ``record_write_example`` contains working
Extbase actions using the injected writer interface. Tests execute
:php:`ActionController::processRequest()`, including argument mapping and HTTP
responses, against real DataHandler writes:

* ``RegistrationController`` validates name/email and a signed invitation before
  creating a registration on a server-selected page. It works without BE
  authentication, ignores editor sessions and rejects invalid invitations and
  invalid input before writing.
* ``BackendController`` edits with the current Backend User, reports denied
  access and returns explicit logical/physical identities for subsequent actions.
* ``ImportController`` requires a backend administrator. It maps structured rows
  into several content elements on different pages, handles selected layout
  columns, existing updates and category MM relations, removes invalid rows after
  preflight, and reports interrupted execution without retrying.
* ``InteroperabilityController`` combines an Extbase repository with the Record
  API and demonstrates their separate identity maps.

A frontend action follows the ordinary Extbase pattern:

.. code-block:: php

   use Psr\Http\Message\ResponseInterface;
   use TYPO3\CMS\Core\Crypto\HashAlgo;
   use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
   use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

   final class RegistrationController extends ActionController
   {
       public function __construct(
           private readonly RecordWriterInterface $writer,
       ) {}

       public function registerAction(
           string $name,
           string $email,
           string $invitation,
       ): ResponseInterface {
           if ($this->request->getMethod() !== 'POST') {
               return $this->jsonResponse('{}')->withStatus(405);
           }
           $name = trim($name);
           $email = strtolower(trim($email));
           if ($name === '' || mb_strlen($name) > 80
               || filter_var($email, FILTER_VALIDATE_EMAIL) === false
           ) {
               return $this->jsonResponse('{}')->withStatus(400);
           }
           if (!$this->hashService->validateHmac(
               $email, 'record-write-registration', $invitation, HashAlgo::SHA256,
           )) {
               return $this->jsonResponse('{}')->withStatus(403);
           }
           $draft = $this->writer->create('tx_example_registration', 123);
           $draft->setValues(['name' => $name, 'email' => $email]);
           $record = $this->writer->save($draft);
           return $this->jsonResponse(json_encode([
               'uid' => $record->getUid(),
           ], JSON_THROW_ON_ERROR))->withStatus(201);
       }
   }

This example's signed invitation is application authorization bound to an email;
an application must deliver and manage invitations appropriately. The fixture
uses its own TCA registration table and page instead of the illustrative names.
Public extension endpoints also need their normal routing and request protection.
The writer does not expose a web endpoint itself.

Extbase interoperability
-----------------------

The writer returns :php:`RecordInterface`, which can be passed to another Record
API service directly, without creating an Extbase entity. An Extbase domain
model is not a :php:`RecordInterface`; use an explicitly chosen table and its UID
when combining the APIs. Their persistence identity maps are **not synchronized**.

A previously loaded Extbase entity remains stale after a writer update. Do not
subsequently flush that stale entity over the saved values. At a suitable
application boundary, reload using Extbase's supported persistence reset, for
example :php:`PersistenceManagerInterface::clearState()` when there are no pending
Extbase changes, then query again. ``clearState()`` discards pending changes; the
writer never calls it implicitly. The interoperability test proves both the stale
entity and the explicitly reloaded result.

Errors and consistency
----------------------

.. code-block:: php

   use TYPO3\CMS\Core\Domain\Persistence\Exception\DataHandlerExecutionException;
   use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
   use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordWriteException;

   try {
       $saved = $recordWriter->save($draft);
   } catch (DataHandlerExecutionException $exception) {
       // DataHandler threw or reported errors: some writes may have succeeded.
       // Inspect errors, getPrevious() and diagnostics; reconcile each source row.
   } catch (RecordPersistenceException $exception) {
       // DataHandler returned, but the requested outcome could not be confirmed.
       // Inspect $exception->diagnostics; never automatically retry this batch.
   } catch (RecordWriteException $exception) {
       // Preflight validation/context/access failure: no execution was attempted.
   }

Specific preflight exceptions cover invalid values, unsupported operations,
missing records, access denial and invalid context. DataHandler errors and
exceptions propagate through :php:`DataHandlerExecutionException`, a subclass
of :php:`RecordPersistenceException`. Other persistence exceptions identify
unconfirmed results after execution, such as missing mappings or a failed
reload. An empty error log alone does not establish success. The adapter also
checks insertion/copy mappings, acknowledged physical writes, command postconditions and saved relations.

DataHandler hooks can intentionally transform values. The returned record exposes
the actual saved values. Acknowledgement of an SQL write does not prove every
requested scalar survived third-party hook evaluation.

There is no general transaction or atomic batch guarantee. Hooks, nested
DataHandler executions, relation updates, cache work and multiple connections can
have side effects before a later failure. The API performs no automatic retries.

There is no atomic optimistic locking. No-op detection is a best-effort read
comparison; concurrent changes can still race with execution. Applications needing
strong conflict control require additional integration with the actual write
operation, not a preflight timestamp comparison.

Impact
======

Extensions can create, update and manage TCA records without constructing
DataHandler maps. Existing read-side interfaces remain unchanged. The new API
is internal and experimental, matching the Record API's current stability.
All writes retain native permission checks, versioning, localization, hooks,
history and cache handling. Frontend writes use a trusted system identity and
require application-level authorization at the extension boundary.

DataHandler, native CLI authentication, CLI provisioning, RelationHandler,
BackendUtility and BackendLayoutView remain unchanged. All integration code is
in the new API classes. The API manages its runtime cache invalidation and
rejects unsupported workspace foreign-field ownership changes and incompatible
DataHandler replacements. Regression tests cover these boundaries, SQL/history
failures and updated backend layouts.

The Forge issue is temporarily recorded as ``000000``. Replace the placeholder
and rename this file when assigning the real review issue.

.. index:: PHP-API, Backend, Frontend, Database, TCA, Workspaces
