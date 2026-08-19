<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;

/**
 * One “when this changes, tell these engines” rule.
 *
 * Rules live in plugin settings rather than in their own project-config path: there are a handful
 * of them, they are pure configuration, and keeping them in the settings model means no config
 * handlers, no records, and no orphaned rows to garbage-collect when a section is renamed.
 *
 * Sections are matched by **handle** deliberately. A UID survives a rename and a handle does not,
 * but a rule pointing at a section that no longer exists should stop firing loudly rather than
 * quietly following the rename to somewhere the operator did not intend.
 */
class Rule extends Model
{
    public const EVENT_CREATE = 'create';
    public const EVENT_UPDATE = 'update';
    public const EVENT_DELETE = 'delete';

    public const EVENTS = [self::EVENT_CREATE, self::EVENT_UPDATE, self::EVENT_DELETE];

    /**
     * The engine handles the flat table shape carries a column for.
     *
     * Listed here rather than asked of the registry because this model must stay usable without an
     * application — and because a column that disappeared when a licence lapsed would silently
     * rewrite everybody's rules.
     */
    public const ENGINE_COLUMNS = ['google', 'indexnow', 'sitemap'];

    /** Matches every section. */
    public const ANY = '*';

    public bool $enabled = true;

    /** A section handle, or {@see self::ANY}. */
    public string $section = self::ANY;

    /** Entry type handles. Empty means every type in the section. */
    public array $entryTypes = [];

    /** Site handles. Empty means every site the entry is enabled for. */
    public array $sites = [];

    /** Engine handles. Empty means every configured engine. */
    public array $engines = [];

    /** @var list<string> */
    public array $events = self::EVENTS;

    protected function defineRules(): array
    {
        return [
            [['section'], 'required'],
            [['section'], 'string'],
            [['enabled'], 'boolean'],
            [['entryTypes', 'sites', 'engines', 'events'], 'each', 'rule' => ['string']],
            [['events'], 'validateEvents', 'skipOnEmpty' => false],
        ];
    }

    /**
     * `skipOnEmpty` is off on purpose: an empty `events` list is exactly the case this rejects, and
     * Yii skips inline validators on empty attributes — an empty array counts as empty.
     */
    public function validateEvents(string $attribute): void
    {
        $unknown = array_diff($this->events, self::EVENTS);

        if ($unknown !== []) {
            $this->addError($attribute, Craft::t('sanka', 'Unknown event: {events}', ['events' => implode(', ', $unknown)]));
        }

        if ($this->events === []) {
            $this->addError($attribute, Craft::t('sanka', 'Choose at least one event, or disable the rule.'));
        }
    }

    /**
     * Build a rule from either shape it arrives in.
     *
     * The canonical shape has `events` and `engines` as lists. The control panel posts a *flat*
     * shape instead — one boolean column per event and per engine — because Craft's editable table
     * has no multi-select column type, and three checkboxes read better in a table than a
     * comma-separated string does anyway. Both are accepted here so the difference never leaks
     * further than this method.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $rule = new self();
        $rule->enabled = (bool)($config['enabled'] ?? true);
        $rule->section = (string)($config['section'] ?? self::ANY);
        $rule->entryTypes = self::stringList($config['entryTypes'] ?? []);
        $rule->sites = self::stringList($config['sites'] ?? []);

        $rule->engines = array_key_exists('engines', $config)
            ? self::stringList($config['engines'])
            : self::flags($config, self::ENGINE_COLUMNS);

        // “Absent” and “present but empty” are different answers and both happen. A config with no
        // event information at all is one nobody has filled in yet, and gets every event. A control
        // panel row with all three boxes *unticked* carries the columns with empty values — that is
        // a deliberate “fires on nothing”, and defaulting it to everything would do the exact
        // opposite of what was asked. So it is kept empty and rejected by validation instead.
        $rule->events = match (true) {
            array_key_exists('events', $config) => self::stringList($config['events']),
            self::carries($config, self::EVENTS) => self::flags($config, self::EVENTS),
            default => self::EVENTS,
        };

        return $rule;
    }


    /**
     * Whether this config was written by something that knows about these columns at all.
     *
     * @param array<string, mixed> $config
     * @param list<string> $keys
     */
    private static function carries(array $config, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $config)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $keys
     * @return list<string>
     */
    private static function flags(array $config, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (!empty($config[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * The flat shape, for the control panel's table.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $columns = ['enabled' => $this->enabled, 'section' => $this->section];

        foreach (self::EVENTS as $event) {
            $columns[$event] = in_array($event, $this->events, true);
        }

        foreach (self::ENGINE_COLUMNS as $engine) {
            // An empty engine list means “every usable engine”, which is what all three boxes
            // ticked means to the reader. Rendering it as three empty boxes would show a rule that
            // sends nowhere, which is the opposite of what it does.
            $columns[$engine] = $this->engines === [] || in_array($engine, $this->engines, true);
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'section' => $this->section,
            'entryTypes' => array_values($this->entryTypes),
            'sites' => array_values($this->sites),
            'engines' => array_values($this->engines),
            'events' => array_values($this->events),
        ];
    }

    public function handlesEvent(string $event): bool
    {
        return $this->enabled && in_array($event, $this->events, true);
    }

    /**
     * Whether this rule covers a given element.
     *
     * Only entries are matched. Categories and assets have URLs too, but “a category page changed”
     * is almost never the thing anyone means by instant indexing, and submitting one costs the same
     * scarce quota as the article that actually changed.
     */
    public function matches(ElementInterface $element): bool
    {
        if (!$this->enabled || !$element instanceof Entry) {
            return false;
        }

        if ($this->section !== self::ANY) {
            $section = $element->getSection();

            if ($section === null || $section->handle !== $this->section) {
                return false;
            }
        }

        if ($this->entryTypes !== [] && !in_array($element->getType()->handle, $this->entryTypes, true)) {
            return false;
        }

        if ($this->sites !== []) {
            $site = Craft::$app->getSites()->getSiteById($element->siteId);

            if ($site === null || !in_array($site->handle, $this->sites, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
