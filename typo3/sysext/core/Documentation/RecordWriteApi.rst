.. include:: /Includes.rst.txt

.. _record-write-api:

Experimental record write API
=============================

Status and scope
----------------

The classes in :php:`TYPO3\CMS\Core\Domain\Persistence` provide an
experimental backend write API for TCA records. They have the same internal
stability status as :php:`RecordFactory`; they are not a stable extension API.
Existing :php:`RecordInterface`, :php:`Record`, :php:`RawRecord` and their
read-side implementations are unchanged.

All persistent record and relation mutations run through :php:`DataHandler`.
Its permissions, TCA evaluation, hooks, history, reference-index updates,
localization, workspace versioning and cache handling remain active. This API
neither uses Extbase persistence nor introduces another persistence framework.

Authentication and dependency injection
---------------------------------------

An authenticated :php:`BackendUserAuthentication` in
:php:`$GLOBALS['BE_USER']` is required. This includes suitably authenticated
command-line processes. Frontend authentication alone is insufficient.
The backend user's workspace and the :php:`Context` workspace must agree.

Inject the writer into a backend service using constructor injection:

.. code-block:: php

   use TYPO3\CMS\Core\Domain\Persistence\RecordWriter;

   final readonly class ContentService
   {
       public function __construct(private RecordWriter $recordWriter) {}
   }

The standard Core service discovery autowires the implementation. Do not register
:php:`DataHandler` as a shared service or reuse an execution instance.

Create and update
-----------------

.. code-block:: php

   $draft = $recordWriter->create('tt_content', 42);
   $draft->setValues([
       'CType' => 'text',
       'header' => 'New title',
       'bodytext' => '<p>Content</p>',
       'hidden' => false,
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

:php:`get()` returns the pending PHP value if assigned; otherwise it returns the
original **raw database value** captured when editing. It does not resolve
relations, apply read-side transformations or synthesize TCA defaults.
A missing value on a new draft raises :php:`InvalidRecordValueException`;
explicit :php:`null` remains distinguishable from a missing assignment.

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
DataHandler defaults, including Page/User-TSconfig, determine the effective type.
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
  not pre-encoded JSON. JSON null is not supported by the current TCA JSON type.
* Static select and radio: a string/integer or a list of those values; a single
  select requires exactly one value. Declared static items are validated.
* Nullable fields: explicit :php:`null`, only where the schema allows it.

UID, PID, timestamps, creation date, deletion and sorting fields, language
identity, synchronization metadata and :php:`t3ver_*` are protected.
Use semantic methods for moving, deleting and localizing. Editable enable columns
such as hidden/starttime/endtime remain supported. Read-only, passthrough and
none fields cannot be assigned directly. Excluded fields require the corresponding
backend permission. Synchronized or language-excluded fields cannot be overridden
on a translation.

Dynamic select/radio item callbacks, FlexForms, folder fields, user-defined field
types and transformed read-side
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
   $edit->relations('categories')->replace([$categoryA, $categoryB]);
   $edit->relations('categories')->reorder([$categoryB, $categoryA]);
   $record = $recordWriter->save($edit);

   $edit = $recordWriter->edit($record);
   $edit->relations('categories')->clear();
   $record = $recordWriter->save($edit);

The field must be a compatible TCA relation. A :php:`RecordInterface` must carry
sufficient raw identity information. :php:`RecordReference` provides an explicit
existing table/UID reference; untyped integer relation IDs are rejected.

:php:`add()` preserves other members and avoids duplicates. :php:`remove()`
removes only the selected member. :php:`replace()` supplies the complete ordered
membership; :php:`clear()` is an empty replacement. :php:`reorder()` requires
exactly the same member set. TCA cardinality limits apply.

Existing members are loaded through :php:`RelationHandler` in backend context,
without frontend enable-column filtering. An incomplete frontend collection is
never used as the authoritative existing relation list. Saved membership and
ordering are checked again after execution.

Supported relations are single references, ordinary select/group relations,
MM relations and standard category reverse-MM relations. Existing foreign-field
IRRE children can be attached, detached and reordered with workspace-aware
ownership checks. Detachment submits
an explicit child datamap update; it does not delete the child. Child permissions
are checked using the existing backend/DataHandler checks. Reparenting an already
owned child, inline cycles and multiple batch owners are rejected.

New IRRE children in a workspace must be created in the same batch, or already
have a workspace version. Existing owned children are versioned with their parent
by DataHandler. Attaching an unowned, unversioned Live child from a workspace is
rejected, preventing an implicit Live foreign-key update.

Arbitrary inverse-MM configurations, duplicate-member relations, inline relations
without a foreign field, symmetric or foreign-selector/table/match-discriminator relations
and TCA file fields are currently rejected. These require additional ownership
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

Returned records use the **physical saved UID**. For a workspace version,
:php:`getRawRecord()->get('t3ver_oid')` identifies its logical Live origin.
Results have a fresh identity map and use the current captured language/workspace
context. They do not reuse old frontend overlays.

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
All drafts are validated before execution; conflicting logical targets and
conflicting child detachments are rejected. Commands are intentionally separate
from draft batches because a cmdmap cannot represent an arbitrary sequence of
commands on one record.

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
       // Inspect $exception->errors and getPrevious(), then reload/reconcile.
   } catch (RecordPersistenceException $exception) {
       // DataHandler returned, but the requested outcome could not be confirmed.
       // Reload/reconcile; never automatically retry a consumed draft or batch.
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

.. index:: PHP-API, Backend, Database, TCA, DataHandler, Workspaces
