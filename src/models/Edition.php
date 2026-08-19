<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application — and so this file can be read as the answer to “what exactly does
 * Pro buy” without chasing calls through six services.
 *
 * Two things enforce it, and they do different jobs:
 *
 * - The settings model **refuses** to save a configuration Lite cannot run, so an operator is told
 *   rather than silently given something other than what they configured.
 * - The services **downgrade** on read, so a site whose licence lapsed keeps indexing with the Lite
 *   feature set instead of breaking. The stored settings are left untouched, and the control panel
 *   keeps showing them, so upgrading restores exactly what was there before.
 */
class Edition
{
    /** Auto-submit rules Lite may keep. */
    public const LITE_MAX_RULES = 3;

    /** The whole GEO half: llms.txt, the crawler policy, the visit log and the readiness audit. */
    public static function allowsGeo(bool $isPro): bool
    {
        return $isPro;
    }

    /** Sitemap resubmission. */
    public static function allowsSitemaps(bool $isPro): bool
    {
        return $isPro;
    }

    /** Scheduled sweeps — “resubmit anything that changed since the last run”. */
    public static function allowsSweeps(bool $isPro): bool
    {
        return $isPro;
    }

    /** Bulk resubmit by section, and multi-select actions on the submissions index. */
    public static function allowsBulk(bool $isPro): bool
    {
        return $isPro;
    }

    /** CSV export of the ledger and the crawler log. */
    public static function allowsExport(bool $isPro): bool
    {
        return $isPro;
    }

    /** Null means unlimited. */
    public static function maxRules(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_RULES;
    }

    /**
     * Everything about the current settings that this edition cannot run, phrased for a human.
     *
     * Returned rather than thrown so the settings screen can show all of them at once instead of
     * making the operator discover them one save at a time.
     *
     * @return list<string>
     */
    public static function problems(Settings $settings, bool $isPro): array
    {
        if ($isPro) {
            return [];
        }

        $problems = [];
        $max = self::maxRules(false);

        if ($max !== null && count($settings->autoSubmitRules) > $max) {
            $problems[] = "Lite keeps {$max} auto-submit rules. Remove " . (count($settings->autoSubmitRules) - $max) . ' to save, or upgrade to Pro.';
        }

        if ($settings->sitemapEnabled) {
            $problems[] = 'Sitemap resubmission is a Pro feature.';
        }

        if ($settings->llmsEnabled || $settings->robotsEnabled || $settings->crawlerLogEnabled) {
            $problems[] = 'The GEO features — llms.txt, the AI crawler policy and the visit log — are Pro features.';
        }

        if ($settings->sweepEnabled) {
            $problems[] = 'Scheduled sweeps are a Pro feature.';
        }

        return $problems;
    }
}
