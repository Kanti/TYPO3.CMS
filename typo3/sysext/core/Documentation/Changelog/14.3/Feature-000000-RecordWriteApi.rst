.. include:: /Includes.rst.txt

.. _feature-000000-1792300000:

================================================
Feature: #000000 - Experimental record write API
================================================

See :issue:`000000`

Description
===========

An experimental backend write API complements the existing Record API.
Mutable :php:`RecordDraft` objects collect explicit changes. The
:php:`TYPO3\CMS\Core\Domain\Persistence\RecordWriter` service executes
creates, updates and semantic commands exclusively through DataHandler.

The implementation supports schema-aware value normalization, typed relations,
MM/category relations, workspace-aware foreign-field inline children,
localization and compatible create/update batches with native NEW references.
It preserves existing read-side records and reloads saved results with a fresh
identity map.

See :ref:`record-write-api` for API examples, authentication requirements,
supported field types and the deliberately unsupported cases.

Impact
======

Backend services can opt into a typed write workflow without building datamaps
and interpreting DataHandler result mappings themselves. Existing Record API
interfaces and implementations remain unchanged. The new API is marked internal
and experimental, matching the current stability of RecordFactory.

Writes retain DataHandler permission checks, workspace versioning, localization,
hooks, history and cache handling. Draft context changes and ambiguous language
fallbacks are rejected. Failed execution consumes participating drafts because
DataHandler operations are not generally atomic and must not be retried blindly.

RelationHandler now checks foreign-field ownership after workspace resolution,
so attached or detached workspace children no longer inherit stale Live
membership. Separate Core regression tests cover this read-side correction.

The Forge issue is temporarily recorded as ``000000``. Replace the placeholder
and rename this file when assigning the real review issue.

.. index:: PHP-API, Backend, Database, TCA, Workspaces
