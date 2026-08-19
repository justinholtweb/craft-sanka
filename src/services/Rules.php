<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\models\Rule;
use justinholtweb\sanka\Plugin;

/**
 * Decides whether a change to an element is worth spending quota on, and where to send it.
 *
 * Kept free of side effects on purpose: this service answers questions and never queues anything,
 * so “would this have been submitted?” is a question the console and the checks can ask without
 * writing to the ledger.
 */
class Rules extends Component
{
    /**
     * The rules in force, capped to what this edition may run.
     *
     * A **downgrade**, not a refusal. Saving more rules than Lite allows is rejected by the settings
     * model so the operator is told; reading them here silently keeps the first few, so a lapsed
     * licence goes on indexing with a smaller rule set rather than stopping dead. Nothing stored is
     * touched, so upgrading restores exactly what was configured.
     *
     * @return list<Rule>
     */
    public function all(): array
    {
        $plugin = Plugin::getInstance();
        $rules = $plugin->getSettings()->getAutoRules();
        $max = Edition::maxRules($plugin->isPro());

        return $max === null ? $rules : array_slice($rules, 0, $max);
    }

    /**
     * @return list<Rule>
     */
    public function matching(ElementInterface $element, string $event): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Rule $rule): bool => $rule->handlesEvent($event) && $rule->matches($element),
        ));
    }

    /**
     * Which engines should hear about this change, or null if none should.
     *
     * Null and `[]` mean different things and both happen: null is “no rule covers this element”,
     * and `[]` is “a rule covers it but every engine it names is switched off or misconfigured”.
     * The caller shows those differently, because only one of them is a problem.
     *
     * @return list<string>|null
     */
    public function enginesFor(ElementInterface $element, string $event): ?array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->autoSubmit) {
            return null;
        }

        $matching = $this->matching($element, $event);

        if ($matching === []) {
            return null;
        }

        $usable = $plugin->engines->getUsableHandles();
        $named = [];
        $wantsEverything = false;

        foreach ($matching as $rule) {
            if ($rule->engines === []) {
                $wantsEverything = true;

                continue;
            }

            foreach ($rule->engines as $handle) {
                $named[$handle] = true;
            }
        }

        if ($wantsEverything) {
            return $usable;
        }

        return array_values(array_intersect(array_keys($named), $usable));
    }

    /**
     * A starting rule for a fresh install: every section, every event, every engine.
     *
     * Offered rather than applied. Sanka ships with auto-submit on but no rules, so nothing is
     * submitted until somebody says what should be.
     */
    public function suggested(): Rule
    {
        return Rule::fromConfig(['section' => Rule::ANY, 'events' => Rule::EVENTS]);
    }
}
