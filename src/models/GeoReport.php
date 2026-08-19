<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

/**
 * The result of auditing one page.
 *
 * Scored per dimension as well as overall, because the two failure modes look identical in a single
 * number and need completely different work: a page that is well written but invisible to a parser
 * fails `machine`, and a page with perfect markup and nothing quotable in it fails `citability`.
 */
final class GeoReport
{
    /**
     * @param list<GeoFinding> $findings
     */
    public function __construct(
        public readonly string $url,
        public readonly array $findings,
        /** Bytes of HTML examined, for the “did it actually fetch the page” question. */
        public readonly int $bytes = 0,
    ) {
    }

    public function score(): int
    {
        return $this->scoreFor(null);
    }

    public function scoreFor(?string $dimension): int
    {
        $earned = 0.0;
        $possible = 0.0;

        foreach ($this->findings as $finding) {
            if ($dimension !== null && $finding->dimension !== $dimension) {
                continue;
            }

            $earned += $finding->credit() * $finding->weight;
            $possible += $finding->weight;
        }

        return $possible <= 0.0 ? 0 : (int)round(($earned / $possible) * 100);
    }

    /**
     * @return array<string, int>
     */
    public function scores(): array
    {
        $scores = [];

        foreach (GeoFinding::DIMENSIONS as $dimension) {
            $scores[$dimension] = $this->scoreFor($dimension);
        }

        return $scores;
    }

    /**
     * @return list<GeoFinding>
     */
    public function ofStatus(string $status): array
    {
        return array_values(array_filter($this->findings, static fn(GeoFinding $f): bool => $f->status === $status));
    }

    /**
     * @return list<GeoFinding>
     */
    public function ofDimension(string $dimension): array
    {
        return array_values(array_filter($this->findings, static fn(GeoFinding $f): bool => $f->dimension === $dimension));
    }

    /**
     * Failures first, then warnings — the order the work should be done in.
     *
     * @return list<GeoFinding>
     */
    public function actionable(): array
    {
        return array_merge($this->ofStatus(GeoFinding::FAIL), $this->ofStatus(GeoFinding::WARN));
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            GeoFinding::PASS => count($this->ofStatus(GeoFinding::PASS)),
            GeoFinding::WARN => count($this->ofStatus(GeoFinding::WARN)),
            GeoFinding::FAIL => count($this->ofStatus(GeoFinding::FAIL)),
        ];
    }
}
