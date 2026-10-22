.. include:: /Includes.rst.txt

.. _feature-111144-1792402600:

====================================================================
Feature: #111144 - Search in dropdown buttons and FormEngine selects
====================================================================

See :issue:`111144`

Description
===========

Editors can now search backend dropdown menus and single-selection fields
to find an option more quickly. Search is added automatically to supported
controls without additional configuration.

With seven or more visible options, the search field appears when the dropdown
opens. With fewer options, start typing to reveal the search field.

Search ignores case and accents. Matching text is highlighted, and the selected
option is marked with a checkmark.

A clear button resets the filter without changing the selection or activating
a dropdown action.

Use the arrow keys to navigate and Enter or a click to choose an option.
Escape cancels, and Tab moves to the next control. Screen readers announce
result counts and option groups.

Arrow Up from the first dropdown result returns to the visible search field.
Pressing Arrow Up there keeps focus in search. Backspace from a dropdown entry
returns to search and deletes the last filter character. Navigating to the
first enabled result scrolls the list to the top to show group headings.
Active options scroll fully into view, accounting for the search field and
the available list height.

The styleguide's Select section demonstrates grouped selects, dropdown
menus, small option lists, preselection and disabled fields.

Usage example
=============

The feature uses the Lit web component
:html:`<typo3-backend-searchable-select>`. In a backend Fluid template, load
the module with :html:`<f:asset.module>` and wrap the native select as shown:

.. code-block:: html

    <f:asset.module
        identifier="@typo3/backend/element/searchable-select-element.js"
    />

    <label for="language" class="form-label">Language</label>
    <typo3-backend-searchable-select>
        <select id="language" name="language" class="form-select">
            <option value="de" selected>Deutsch</option>
            <option value="en">English</option>
            <option value="fr">Français</option>
        </select>
    </typo3-backend-searchable-select>

Keep the label, name, options and change handlers on the native select.
The native select retains form submission, validation, reset, icon updates
and onChange actions. The component updates that select and dispatches its
native :js:`input` and :js:`change` events when the user chooses a different
option.

FormEngine fields using :php:`selectSingle` or :php:`country` and dropdown
buttons created through
:php:`ComponentFactory::createDropDownButton()` already include the wrapper
and load the module automatically.

Impact
======

Existing dropdown buttons rendered by :php:`DropDownButton`, including those
created through :php:`ComponentFactory::createDropDownButton()`, are enhanced
automatically. The same applies to FormEngine fields rendered by
:php:`SelectSingleElement` or :php:`SelectCountryElement` when :php:`size` is
unset or at most :php:`1`. Multiple selects and listboxes with :php:`size > 1`
remain native. Other select controls are not enhanced automatically.

On mobile devices such as smartphones and tablets operated by touch without
hover support, native selects retain their usual selection interface.
Dropdown menus still provide search.

There is currently no configuration option to disable the enhancement.
The seven-option threshold controls only the initial visibility of the search
field. Controls with fewer options are also enhanced and reveal search on
typing.

The additional :html:`<typo3-backend-searchable-select>` wrapper changes the DOM
structure. For enhanced native selects, JavaScript creates a separate visible
trigger button and option list. The original select is visually hidden but
retained for form values and events. Existing dropdown item elements and their
action handlers are retained.

Preserving form values and actions does not imply markup compatibility.
Extensions using custom CSS selectors, JavaScript DOM queries or traversal, or
markup assertions must review their integrations and adjust them where
necessary.

.. index:: Backend, JavaScript, PHP-API, ext:backend
