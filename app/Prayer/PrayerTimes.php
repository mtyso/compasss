<?php

namespace App\Prayer;

/**
 * The day's prayer times as the Muslim Board of Uzbekistan publishes them (docs/PLAN.md, D40), worked out
 * here so nothing has to be fetched. The taqvim is Tashkent's, Hanafi: Fajr and Isha with the sun 15.5°
 * below the horizon, Asr when a shadow is twice its object plus the noon shadow, Maghrib 3 minutes after
 * sunset. Other cities take Tashkent's minutes moved by their own offset (Cities). Checked against the
 * Board's table for 153 days of 2026: every time within a minute.
 *
 * The sun's position is the usual low-precision one (U.S. Naval Observatory), good to a minute here.
 */
final class PrayerTimes
{
    /** The five daily prayers, in the order of the day. */
    public const NAMES = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    private const LATITUDE = 41.2995;

    private const LONGITUDE = 69.2401;

    private const UTC_OFFSET = 5;

    private const FAJR_ANGLE = 15.5;

    private const ISHA_ANGLE = 15.5;

    /** The sun's upper edge at the horizon, with refraction. */
    private const SUNSET_ANGLE = 0.833;

    /** Hanafi: a shadow twice the object. */
    private const ASR_SHADOW = 2;

    private const MAGHRIB_AFTER_SUNSET = 3;

    /**
     * Tashkent's times on a date, in minutes from its midnight. `midnight` is the middle of the night, between
     * sunset and the next Fajr, where the preferred time for Isha ends; it can be past 1440.
     *
     * @return array{fajr: int, sunrise: int, dhuhr: int, asr: int, maghrib: int, isha: int, midnight: int}
     */
    public static function tashkent(string $date): array
    {
        $day = self::hours($date);
        $next = self::hours(date('Y-m-d', strtotime($date.' +1 day')));
        $round = fn (float $hours) => (int) floor($hours * 60 + 0.5);

        return [
            'fajr' => $round($day['fajr']),
            'sunrise' => $round($day['sunrise']),
            'dhuhr' => $round($day['dhuhr']),
            'asr' => $round($day['asr']),
            // As the Board's table: three minutes after sunset, never early.
            'maghrib' => (int) ceil($day['sunset'] * 60 + self::MAGHRIB_AFTER_SUNSET),
            'isha' => $round($day['isha']),
            'midnight' => $round(($day['sunset'] + 24 + $next['fajr']) / 2),
        ];
    }

    /**
     * A city's times: Tashkent's moved by the city's minutes, as in the taqvim.
     *
     * @return array{fajr: int, sunrise: int, dhuhr: int, asr: int, maghrib: int, isha: int, midnight: int}
     */
    public static function for(string $date, int $offset = 0): array
    {
        return array_map(fn (int $minutes) => $minutes + $offset, self::tashkent($date));
    }

    /** «05:32» from minutes after midnight; past midnight wraps to the next day's clock. */
    public static function clock(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @return array{fajr: float, sunrise: float, dhuhr: float, asr: float, sunset: float, isha: float} hours of local time */
    private static function hours(string $date): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $jd = self::julianDay($y, $m, $d) - self::LONGITUDE / 360;
        $shift = self::UTC_OFFSET - self::LONGITUDE / 15;

        $sunrise = self::sunAt(self::SUNSET_ANGLE, $jd, 6, true);
        $sunset = self::sunAt(self::SUNSET_ANGLE, $jd, 18, false);
        // The sun always gets this low at Uzbekistan's latitudes; the seventh of the night is only a guard.
        $night = 24 - ($sunset - $sunrise);
        $fajr = self::sunAt(self::FAJR_ANGLE, $jd, 5, true) ?? $sunrise - $night / 7;
        $isha = self::sunAt(self::ISHA_ANGLE, $jd, 18, false) ?? $sunset + $night / 7;

        return [
            'fajr' => $fajr + $shift,
            'sunrise' => $sunrise + $shift,
            'dhuhr' => self::noon($jd, 12) + $shift,
            'asr' => self::asr($jd) + $shift,
            'sunset' => $sunset + $shift,
            'isha' => $isha + $shift,
        ];
    }

    /** When the sun is $angle degrees below the horizon, before noon or after; null if it never gets there. */
    private static function sunAt(float $angle, float $jd, float $guess, bool $morning): ?float
    {
        [$declination] = self::sun($jd + $guess / 24);
        $lat = deg2rad(self::LATITUDE);
        $dec = deg2rad($declination);
        $cos = (-sin(deg2rad($angle)) - sin($dec) * sin($lat)) / (cos($dec) * cos($lat));
        if ($cos < -1 || $cos > 1) {
            return null;
        }
        $span = rad2deg(acos($cos)) / 15;

        return self::noon($jd, $guess) + ($morning ? -$span : $span);
    }

    private static function asr(float $jd): float
    {
        [$declination] = self::sun($jd + 13 / 24);
        $altitude = -rad2deg(atan(1 / (self::ASR_SHADOW + tan(deg2rad(abs(self::LATITUDE - $declination))))));

        return (float) self::sunAt($altitude, $jd, 13, false);
    }

    /** Solar noon, in hours of local mean time. */
    private static function noon(float $jd, float $guess): float
    {
        [, $equation] = self::sun($jd + $guess / 24);

        return self::wrap(12 - $equation, 24);
    }

    /** @return array{0: float, 1: float} the sun's declination (degrees) and the equation of time (hours) */
    private static function sun(float $jd): array
    {
        $d = $jd - 2451545.0;
        $g = deg2rad(self::wrap(357.529 + 0.98560028 * $d, 360));
        $q = self::wrap(280.459 + 0.98564736 * $d, 360);
        $l = deg2rad(self::wrap($q + 1.915 * sin($g) + 0.020 * sin(2 * $g), 360));
        $e = deg2rad(23.439 - 0.00000036 * $d);

        $rightAscension = self::wrap(rad2deg(atan2(cos($e) * sin($l), cos($l))) / 15, 24);
        $declination = rad2deg(asin(sin($e) * sin($l)));
        $equation = self::wrap($q / 15 - $rightAscension + 12, 24) - 12;

        return [$declination, $equation];
    }

    private static function julianDay(int $year, int $month, int $day): float
    {
        if ($month <= 2) {
            $year--;
            $month += 12;
        }
        $a = intdiv($year, 100);
        $b = 2 - $a + intdiv($a, 4);

        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }

    private static function wrap(float $value, float $range): float
    {
        return $value - $range * floor($value / $range);
    }
}
