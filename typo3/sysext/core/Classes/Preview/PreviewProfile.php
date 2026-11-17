<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Preview;

/**
 * What a preview simulates, chosen in the backend when it signs a preview
 * token. What the editor may see is not part of it: the site checks the
 * rights of the editor on every request.
 *
 * @internal
 */
final readonly class PreviewProfile
{
    public bool $loggedIn;

    /**
     * @param int $date simulated timestamp, 0 for the current time
     * @param list<int> $groups simulated frontend user groups
     * @param bool $loggedIn whether a frontend login is simulated, always if groups are
     */
    public function __construct(
        public int $workspace,
        public bool $hiddenPages,
        public bool $hiddenContent,
        public int $date,
        public array $groups,
        bool $loggedIn = false,
    ) {
        $this->loggedIn = $loggedIn || $groups !== [];
    }

    /**
     * The pages as a visitor sees them now: live, without hidden records, at the current
     * time and without a frontend login
     */
    public static function visitor(): self
    {
        return new self(0, false, false, 0, []);
    }

    /**
     * Whether the profile shows the live view as visitors see it now: live, without
     * hidden records, at the current time and without a frontend login. A token of an
     * editor may carry such a profile too. Whether a token shows live, as the visitor
     * token of the live frame does, says PreviewToken::showsLive().
     */
    public function showsLiveView(): bool
    {
        if ($this->workspace !== 0) {
            return false;
        }
        if ($this->hiddenPages) {
            return false;
        }
        if ($this->hiddenContent) {
            return false;
        }
        if ($this->date !== 0) {
            return false;
        }
        return !$this->loggedIn;
    }

    public function toArray(): array
    {
        return [
            'ws' => $this->workspace,
            'hiddenPages' => $this->hiddenPages,
            'hiddenContent' => $this->hiddenContent,
            'date' => $this->date,
            'groups' => $this->groups,
            'login' => $this->loggedIn,
        ];
    }

    /**
     * The profile as a token carries it
     *
     * @throws \UnexpectedValueException whose message names the first field that does not fit
     */
    public static function fromArray(array $data): self
    {
        $workspace = $data['ws'] ?? null;
        if (!is_int($workspace) || $workspace < 0) {
            throw self::invalidField('ws is not a workspace id');
        }
        $hiddenPages = $data['hiddenPages'] ?? null;
        if (!is_bool($hiddenPages)) {
            throw self::invalidField('hiddenPages is not a boolean');
        }
        $hiddenContent = $data['hiddenContent'] ?? null;
        if (!is_bool($hiddenContent)) {
            throw self::invalidField('hiddenContent is not a boolean');
        }
        $date = $data['date'] ?? null;
        if (!is_int($date) || $date < 0) {
            throw self::invalidField('date is not a timestamp');
        }
        $loggedIn = $data['login'] ?? null;
        if (!is_bool($loggedIn)) {
            throw self::invalidField('login is not a boolean');
        }
        $groups = $data['groups'] ?? null;
        if (!is_array($groups) || !array_is_list($groups)) {
            throw self::invalidField('groups is not a list of group ids');
        }
        foreach ($groups as $group) {
            if (!is_int($group) || $group < 1) {
                throw self::invalidField('groups is not a list of group ids');
            }
        }
        return new self($workspace, $hiddenPages, $hiddenContent, $date, $groups, $loggedIn);
    }

    private static function invalidField(string $reason): \UnexpectedValueException
    {
        return new \UnexpectedValueException($reason, 1794908493);
    }
}
