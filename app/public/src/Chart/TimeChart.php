<?php

namespace App\Chart;

/**
 * The geometry of a time chart drawn as SVG by templates/_parts/time_chart.html.twig: scales, paths, day and value
 * ticks, the "now" line and labelled markers. Values are in the series' unit (%, %/h); x is a Unix time.
 */
final class TimeChart
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 320;
    private const int LEFT = 58;
    private const int RIGHT = 14;
    private const int TOP = 30;
    private const int BOTTOM = 30;
    /** The least distance between two marker labels, in the chart's width units ("+100%" is about 40 wide). */
    public const int MARKER_GAP = 44;

    /** @var list<array{key: string, d: string, area: ?string, dashed: bool, color: string, label: string}> */
    public array $series = [];
    /** @var list<array{x: float, y: float, text: string}> */
    public array $markers = [];
    /** @var list<array{t: int, v: float, s: string}> points for the hover read-out (s: series label) */
    public array $points = [];

    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly float $max,
        public readonly string $unit = '%',
        public readonly ?int $now = null,
        /** @var list<string> short day names from Sunday, for the day ticks */
        private readonly array $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    ) {
    }

    /** @param list<array{t: int, v: float}> $points */
    public function add(string $key, string $label, array $points, string $color, bool $dashed = false, bool $area = false): void
    {
        $points = array_values(array_filter($points, fn (array $p) => $p['t'] >= $this->from && $p['t'] <= $this->to));
        if ([] === $points) {
            return;
        }
        $line = [];
        foreach ($points as $point) {
            $line[] = \sprintf('%.1f,%.1f', $this->x($point['t']), $this->y($point['v']));
            $this->points[] = ['t' => $point['t'], 'v' => round($point['v'], 1), 's' => $label];
        }
        $d = 'M'.implode(' L', $line);
        $base = $this->y(0);
        $this->series[] = [
            'key' => $key,
            'd' => $d,
            'area' => $area ? \sprintf('%s L%.1f,%.1f L%.1f,%.1f Z', $d, $this->x(end($points)['t']), $base, $this->x($points[0]['t']), $base) : null,
            'dashed' => $dashed,
            'color' => $color,
            'label' => $label,
        ];
    }

    /** A labelled point; one closer than MARKER_GAP to the last one kept is left out, so labels never overlap. */
    public function mark(int $t, float $v, string $text): void
    {
        if ($t < $this->from || $t > $this->to) {
            return;
        }
        $x = $this->x($t);
        $last = end($this->markers);
        if (false !== $last && abs($x - $last['x']) < self::MARKER_GAP) {
            return;
        }
        $this->markers[] = ['x' => $x, 'y' => $this->y($v), 'text' => $text];
    }

    public function x(int $t): float
    {
        return self::LEFT + ($t - $this->from) / max(1, $this->to - $this->from) * (self::WIDTH - self::LEFT - self::RIGHT);
    }

    public function y(float $v): float
    {
        return self::TOP + (1 - min(max($v, 0), $this->max) / max(0.0001, $this->max)) * (self::HEIGHT - self::TOP - self::BOTTOM);
    }

    public function nowX(): ?float
    {
        return null !== $this->now && $this->now >= $this->from && $this->now <= $this->to ? $this->x($this->now) : null;
    }

    public function left(): int
    {
        return self::LEFT;
    }

    public function right(): int
    {
        return self::WIDTH - self::RIGHT;
    }

    public function bottom(): int
    {
        return self::HEIGHT - self::BOTTOM;
    }

    /** @return list<array{x: float, label: string}> local midnights, labelled "Pr 9" */
    public function dayTicks(): array
    {
        $ticks = [];
        $day = (new \DateTimeImmutable('@'.$this->from))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
        if ($day->getTimestamp() < $this->from) {
            $day = $day->modify('+1 day');
        }
        $every = $this->to - $this->from > 20 * 86400 ? 7 : ($this->to - $this->from > 3 * 86400 ? 1 : 0);
        if (0 === $every) {
            for ($t = $this->from - $this->from % 21600 + 21600; $t <= $this->to; $t += 21600) {
                $ticks[] = ['x' => $this->x($t), 'label' => (new \DateTimeImmutable('@'.$t))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i')];
            }

            return $ticks;
        }
        for (; $day->getTimestamp() <= $this->to; $day = $day->modify("+$every day")) {
            $ticks[] = ['x' => $this->x($day->getTimestamp()), 'label' => $this->weekdays[(int) $day->format('w')].' '.$day->format('j')];
        }

        return $ticks;
    }

    /** @return list<array{y: float, label: string}> */
    public function valueTicks(): array
    {
        return array_map(fn (float $v) => ['y' => $this->y($v), 'label' => round($v).$this->unit], [0, $this->max / 2, $this->max]);
    }
}
