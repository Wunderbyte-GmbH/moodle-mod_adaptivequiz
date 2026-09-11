<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A value object encapsulating user preferences to set up the report table.
 *
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaptivequiz\local\report\users_attempts\user_preferences;

use stdClass;

/**
 * User preferences.
 */
final class user_preferences {
    /** Per page options. */
    public const PER_PAGE_OPTIONS = [5, 10, 15, 20, 25, 50];

    /** Per page default. */
    public const PER_PAGE_DEFAULT = 15;

    /** Show initials bar default. */
    public const SHOW_INITIALS_BAR_DEFAULT = 1;

    /** Persistent filter default. */
    public const PERSISTENT_FILTER_DEFAULT = 0;

    /**
     * @var int $perpage
     */
    private $perpage;

    /**
     * @var int $showinitialsbar Represents a boolean value, but defined as an int, as it's stored as an int.
     */
    private $showinitialsbar;

    /**
     * @var int $persistentfilter Same as for $showinitialsbar above.
     */
    private $persistentfilter;

    /**
     * @var filter_user_preferences|null $filter
     */
    private $filter;

    /**
     * Construct.
     *
     * @param int $perpage Perpage.
     * @param int $showinitialsbar Showinitialsbar.
     * @param int $persistentfilter Persistentfilter.
     * @param ?filter_user_preferences $filter Filter.
     */
    private function __construct(
        int $perpage,
        int $showinitialsbar,
        int $persistentfilter,
        ?filter_user_preferences $filter
    ) {
        $this->perpage = in_array($perpage, self::PER_PAGE_OPTIONS) ? $perpage : self::PER_PAGE_DEFAULT;

        $this->showinitialsbar = in_array($showinitialsbar, [0, 1])
            ? $showinitialsbar
            : self::SHOW_INITIALS_BAR_DEFAULT;

        $this->persistentfilter = in_array($persistentfilter, [0, 1])
            ? $persistentfilter
            : self::PERSISTENT_FILTER_DEFAULT;

        $this->filter = $filter;
    }

    /**
     * Rows per page.
     *
     * @return int
     */
    public function rows_per_page(): int {
        return $this->perpage;
    }

    /**
     * Show initials bar.
     *
     * @return bool
     */
    public function show_initials_bar(): bool {
        return (bool) $this->showinitialsbar;
    }

    /**
     * Persistent filter.
     *
     * @return bool
     */
    public function persistent_filter(): bool {
        return (bool) $this->persistentfilter;
    }

    /**
     * Filter.
     *
     * @return filter_user_preferences
     */
    public function filter(): ?filter_user_preferences {
        return $this->filter;
    }

    /**
     * Returns whether filter preference.
     *
     * @return bool
     */
    public function has_filter_preference(): bool {
        return $this->filter !== null;
    }

    /**
     * With filter preference.
     *
     * @param filter_user_preferences $preference Preference.
     * @return self
     */
    public function with_filter_preference(filter_user_preferences $preference): self {
        return new self($this->perpage, $this->showinitialsbar, $this->persistentfilter, $preference);
    }

    /**
     * Without filter preference.
     *
     * @return self
     */
    public function without_filter_preference(): self {
        return new self($this->perpage, $this->showinitialsbar, $this->persistentfilter, null);
    }

    /**
     * As array.
     *
     * @return array
     */
    public function as_array(): array {
        $return = ['perpage' => $this->perpage, 'showinitialsbar' => $this->showinitialsbar,
            'persistentfilter' => $this->persistentfilter];
        $return['filter'] = ($this->filter === null) ? null : $this->filter->as_array();

        return $return;
    }

    /**
     * From array.
     *
     * @param array $prefs Prefs.
     * @return self
     */
    public static function from_array(array $prefs): self {
        $filter = array_key_exists('filter', $prefs) ? $prefs['filter'] : null;

        return new self(
            array_key_exists('perpage', $prefs) ? $prefs['perpage'] : self::PER_PAGE_DEFAULT,
            array_key_exists('showinitialsbar', $prefs) ? $prefs['showinitialsbar'] : self::SHOW_INITIALS_BAR_DEFAULT,
            array_key_exists('persistentfilter', $prefs) ? $prefs['persistentfilter'] : self::PERSISTENT_FILTER_DEFAULT,
            ($filter === null) ? null : filter_user_preferences::from_array($filter)
        );
    }

    /**
     * From plain object.
     *
     * @param stdClass $object Object.
     * @return self
     */
    public static function from_plain_object(stdClass $object): self {
        return self::from_array((array) $object);
    }

    /**
     * Defaults.
     *
     * @return self
     */
    public static function defaults(): self {
        return new self(self::PER_PAGE_DEFAULT, self::SHOW_INITIALS_BAR_DEFAULT, self::PERSISTENT_FILTER_DEFAULT, null);
    }
}
