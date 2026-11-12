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
     * Whether the pages are shown as a visitor sees them now: live, without
     * hidden records, at the current time and without a frontend login.
     */
    public function isVisitorView(): bool
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
     * The profile as a token carries it, or null if the data is no profile
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_int($data['ws'] ?? null)) {
            return null;
        }
        if ($data['ws'] < 0) {
            return null;
        }
        if (!is_bool($data['hiddenPages'] ?? null)) {
            return null;
        }
        if (!is_bool($data['hiddenContent'] ?? null)) {
            return null;
        }
        if (!is_int($data['date'] ?? null)) {
            return null;
        }
        if ($data['date'] < 0) {
            return null;
        }
        if (!is_bool($data['login'] ?? null)) {
            return null;
        }
        $groups = $data['groups'] ?? null;
        if (!is_array($groups)) {
            return null;
        }
        if (!array_is_list($groups)) {
            return null;
        }
        foreach ($groups as $group) {
            if (!is_int($group)) {
                return null;
            }
            if ($group < 1) {
                return null;
            }
        }
        return new self($data['ws'], $data['hiddenPages'], $data['hiddenContent'], $data['date'], $groups, $data['login']);
    }
}
