/**
 * Small helpers every adapter shares, so React, Vue and Livewire show
 * people and read inputs the same way.
 */

/**
 * The people in a field right now, leaving out whoever is away.
 *
 * @param {Person[]} people
 * @param {string} field
 */
export const peopleIn = (people, field) => people.filter((person) => person.field === field && !person.idle)

/**
 * The faces to show, and how many more there are.
 *
 * @param {Person[]} people
 * @param {number} [limit]
 * @returns {{shown: Person[], more: number}}
 */
export function avatarsOf(people, limit = 5) {
    return { shown: people.slice(0, limit), more: Math.max(0, people.length - limit) }
}

/**
 * What an input's change event means as a form value: a checkbox's
 * `checked`, a multiple select's values, any other input's `value`. A value
 * that is not an event, such as a custom component's, is taken as it is.
 *
 * @param {any} event
 */
export function valueOf(event) {
    const target = event?.target

    if (!target || typeof target !== 'object' || !('value' in target)) return event
    if (target.type === 'checkbox') return target.checked
    if (target.multiple && target.selectedOptions) return [...target.selectedOptions].map((option) => option.value)

    return target.value
}
