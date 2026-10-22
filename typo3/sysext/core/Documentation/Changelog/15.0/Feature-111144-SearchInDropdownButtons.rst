..  include:: /Includes.rst.txt

..  _feature-111144-1792396800:

====================================================================
Feature: #111144 - Search in dropdown buttons and FormEngine selects
====================================================================

See :issue:`111144`

Description
===========

Dropdown buttons returned by
:php:`\TYPO3\CMS\Backend\Template\Components\ComponentFactory::createDropDownButton()`
and FormEngine fields with :php:`renderType` :php:`selectSingle` or
:php:`selectCountry` support filtering their existing options.

When a dropdown has seven or more visible options, the search field is shown
and focused automatically on opening. With fewer options, it remains hidden
until typing starts on the focused dropdown. The first character is included
in the search. Hidden options, headings and dividers do not count towards the
threshold; disabled options do count. No PHP visibility setting is needed.

Filtering ignores case and accents. Matching text uses the backend highlight
style and retains its original spelling and spacing. Icons, formatting and
existing action handlers remain intact. Highlights update with the query and
are removed when clearing or closing.

Arrow keys navigate visible enabled options. Enter activates a dropdown action
or commits a FormEngine selection. Escape closes without changing the selection
and returns focus to the trigger, including when there are no matches. Native
text editing and Tab navigation remain available. Result counts and empty
results are announced to assistive technology. Closing resets the query and
restores the original options and automatic search visibility.

Impact
======

Existing dropdown actions, icons, active states and disabled entries are
retained. FormEngine keeps its native select as the form control: submitted
values, validation, reset, icon updates and existing onChange actions continue
to work. Multiple selects and listboxes keep their existing presentation.

The styleguide demonstrates dropdown buttons and selects with fewer than seven
options and with seven or more options.

..  index:: Backend, JavaScript, PHP-API, TCA, ext:backend
