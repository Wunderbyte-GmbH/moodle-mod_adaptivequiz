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

namespace mod_adaptivequiz\local\catmodel;

use coding_exception;
use core_component;

/**
 * Resolves the handler a CAT model subplugin provides for a given extension point.
 *
 * The host offers extension points, it does not implement CAT model logic. Every place in the
 * host that may be extended by a CAT model asks this class for a handler and falls back to the
 * default behaviour when none is returned. There is deliberately no other way to reach a
 * subplugin from the host.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catmodel_resolver {
    /** @var string Namespace below a subplugin in which handlers are looked up. */
    private const HANDLER_NAMESPACES = 'local\catmodel';

    /**
     * Returns whether an activity instance delegates to a CAT model at all.
     *
     * @param string|null $catmodel Name of the CAT model subplugin, without the frankenstyle prefix.
     * @return bool
     */
    public static function is_configured(?string $catmodel): bool {
        return !empty($catmodel);
    }

    /**
     * Returns the handler a CAT model provides for the given extension point, if any.
     *
     * Three outcomes, and they are the whole contract:
     *  - no CAT model configured: null, the caller uses the host default;
     *  - CAT model configured but this extension point not implemented: null, same;
     *  - CAT model configured but not installed: coding_exception, never a fatal.
     *
     * @param string|null $catmodel Name of the CAT model subplugin, without the frankenstyle prefix.
     * @param string $interface Fully qualified name of the extension point interface.
     * @return object|null An instance implementing $interface, or null when the host default applies.
     */
    public static function handler(?string $catmodel, string $interface): ?object {
        if (!self::is_configured($catmodel)) {
            return null;
        }

        $component = 'adaptivequizcatmodel_' . $catmodel;
        if (!in_array($catmodel, array_keys(core_component::get_plugin_list('adaptivequizcatmodel')), true)) {
            throw new coding_exception(
                "The CAT model '{$catmodel}' is configured for an adaptive quiz instance but is not installed."
            );
        }

        foreach (self::candidate_classes($component) as $classname) {
            if (is_subclass_of($classname, $interface)) {
                return new $classname();
            }
        }

        return null;
    }

    /**
     * Returns what a CAT model reports through a plugin callback function, if it offers one.
     *
     * Some extension points are a single value rather than a behaviour - the URL of the CAT
     * model's own attempts report, for instance. Moodle's own mechanism for that is a callback
     * function, and the CAT models use it. Routing the lookup through here keeps the rule intact
     * that the host reaches a subplugin in exactly one place.
     *
     * @param string|null $catmodel Name of the CAT model subplugin, without the frankenstyle prefix.
     * @param string $callback Name of the callback, without the component prefix.
     * @param mixed ...$arguments Arguments passed on to the callback.
     * @return mixed The return value of the callback, or null when the CAT model offers none.
     */
    public static function callback(?string $catmodel, string $callback, ...$arguments) {
        if (!self::is_configured($catmodel)) {
            return null;
        }

        $component = 'adaptivequizcatmodel_' . $catmodel;
        $functions = get_plugin_list_with_function('adaptivequizcatmodel', $callback);

        if (!array_key_exists($component, $functions)) {
            return null;
        }

        return $functions[$component](...$arguments);
    }

    /**
     * Returns the class names a subplugin offers below its catmodel namespace.
     *
     * @param string $component Frankenstyle name of the CAT model subplugin.
     * @return string[]
     */
    private static function candidate_classes(string $component): array {
        $classes = [];
        foreach (['instance', 'form'] as $area) {
            $classes += core_component::get_component_classes_in_namespace(
                $component,
                self::HANDLER_NAMESPACES . '\\' . $area
            );
        }

        // The item administration sits in its own namespace, mirroring the host.
        $classes += core_component::get_component_classes_in_namespace($component, 'local\\itemadministration');

        return array_keys($classes);
    }
}
