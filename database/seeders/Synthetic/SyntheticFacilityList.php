<?php

declare(strict_types=1);

namespace Database\Seeders\Synthetic;

/**
 * SYNTHETIC wards and facilities for local and staging, until the client signs off the real
 * facility master list (open question Q-06). Everything synthetic lives here, so a future
 * `edqa:facilities:import-master-list` replaces this class and nothing else.
 *
 * Built the way the prototype's buildSample() does it (kaduna-edqa-prototype-v4.html):
 * public facility counts per LGA as read off the live tool's Monitor page, the prototype's
 * ward names and name patterns. Deterministic: the same list every run.
 *
 * Facility codes start with "SYN-" so synthetic rows can never be mistaken for, or collide
 * with, real registry codes.
 */
final class SyntheticFacilityList
{
    /** Public facilities per LGA (prototype LGA_PUBLIC; 1,957 in total). */
    public const PUBLIC_PER_LGA = [
        'Sanga' => 98, 'Zangon Kataf' => 95, 'Kaduna South' => 93, 'Giwa' => 93, 'Chikun' => 91,
        'Lere' => 91, 'Kaura' => 89, "Jema'a" => 89, 'Soba' => 89, 'Zaria' => 87, 'Sabon Gari' => 86,
        'Kachia' => 85, 'Igabi' => 83, 'Jaba' => 83, 'Makarfi' => 82, 'Ikara' => 81, 'Kubau' => 81,
        'Kagarko' => 80, 'Kaduna North' => 80, 'Birnin Gwari' => 78, 'Kajuru' => 76, 'Kauru' => 75,
        'Kudan' => 72,
    ];

    /** Private facilities in total (CONVENTION.md §8), spread across the 23 LGAs. */
    public const PRIVATE_TOTAL = 219;

    public const WARDS_PER_LGA = 10;

    /** Prototype WARDS ("Iddah" appears twice, as in the prototype). */
    private const WARD_NAMES = [
        'Kakangi', 'Chikaji', 'Zabi', 'Kufana', 'Iddah', 'Danmahawayi', 'Kawo', 'Ungwan Rimi',
        'Tudun Wada', 'Sabon Tasha', 'Kujama', 'Rigachikun', 'Wusasa', 'Samaru', 'Bomo', 'Randagi',
        'Kuyello', 'Kwassam', 'Manchok', 'Saminaka', 'Zonkwa', 'Anchau', 'Hunkuyi', 'Maigana',
        'Buruku', 'Gwantu', 'Pambeguwa', 'Kidandan', 'Dogarawa', 'Basawa', 'Gonin Gora', 'Barnawa',
        'Malali', 'Rido', 'Kakau', 'Turunku', 'Damau', 'Likoro', 'Kamuru', 'Iddah',
    ];

    /** Prototype PATTERNS; %s is the ward name. */
    private const NAME_PATTERNS = [
        'PHC %s', 'HC %s', '%s Health Clinic', '%s Health Post', '%s Maternity', '%s Dispensary',
        '%s Medical Centre', '%s Mission Health Centre',
    ];

    /**
     * Ten wards per LGA: a window over the prototype's ward names that shifts by four for each
     * LGA (alphabetical), as the prototype does. A name already used in the same LGA is
     * prefixed with the LGA.
     *
     * @return list<array{lga: string, name: string, code: string}>
     */
    public function wards(): array
    {
        $wards = [];

        foreach ($this->lgas() as $index => $lga) {
            $used = [];
            for ($i = 0; $i < self::WARDS_PER_LGA; $i++) {
                $name = self::WARD_NAMES[($index * 4 + $i) % count(self::WARD_NAMES)];
                if (in_array($name, $used, true)) {
                    $name = "{$lga} {$name}";
                }
                $used[] = $name;
                $wards[] = ['lga' => $lga, 'name' => $name, 'code' => sprintf('SYN-%s-W%02d', $this->lgaKey($lga), $i + 1)];
            }
        }

        return $wards;
    }

    /**
     * @return list<array{lga: string, ward: string, code: string, name: string, level: string, ownership: string}>
     */
    public function facilities(): array
    {
        $wardsByLga = [];
        foreach ($this->wards() as $ward) {
            $wardsByLga[$ward['lga']][] = $ward['name'];
        }

        $facilities = [];
        foreach ($this->lgas() as $lga) {
            $seq = 0;
            // Private facilities start seven wards along, as in the prototype.
            foreach ([['public', self::PUBLIC_PER_LGA[$lga], 0], ['private', $this->privateCount($lga), 7]] as [$ownership, $count, $offset]) {
                for ($i = 0; $i < $count; $i++) {
                    $n = $i + $offset;
                    $ward = $wardsByLga[$lga][$n % self::WARDS_PER_LGA];
                    $pattern = self::NAME_PATTERNS[(int) floor(self::fraction("pt{$lga}{$ownership}{$n}") * count(self::NAME_PATTERNS))];
                    $group = intdiv($n, self::WARDS_PER_LGA);

                    $facilities[] = [
                        'lga' => $lga,
                        'ward' => $ward,
                        'code' => sprintf('SYN-%s-%04d', $this->lgaKey($lga), ++$seq),
                        'name' => sprintf($pattern, $ward).($group > 0 ? ' '.($group + 1) : ''),
                        'level' => $this->level("{$lga}{$ownership}{$n}"),
                        'ownership' => $ownership,
                    ];
                }
            }
        }

        return $facilities;
    }

    /** @return list<string> */
    private function lgas(): array
    {
        $lgas = array_keys(self::PUBLIC_PER_LGA);
        sort($lgas);

        return $lgas;
    }

    /**
     * 219 private facilities: nine per LGA, plus one more for the twelve LGAs with the most
     * public facilities.
     */
    private function privateCount(string $lga): int
    {
        $base = intdiv(self::PRIVATE_TOTAL, count(self::PUBLIC_PER_LGA));
        $extra = self::PRIVATE_TOTAL - $base * count(self::PUBLIC_PER_LGA);
        $largest = array_slice(array_keys(self::PUBLIC_PER_LGA), 0, $extra);   // already sorted by count

        return $base + (in_array($lga, $largest, true) ? 1 : 0);
    }

    /** About 7% secondary and 0.5% tertiary, as in the prototype. */
    private function level(string $seed): string
    {
        return match (true) {
            self::fraction("lv{$seed}") > 0.93 => 'secondary',
            self::fraction("lv2{$seed}") > 0.995 => 'tertiary',
            default => 'primary',
        };
    }

    /** A letters-only key for synthetic codes, e.g. "ZANGONKATAF". */
    private function lgaKey(string $lga): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $lga));
    }

    /** Deterministic number in [0, 1) from a seed string. */
    private static function fraction(string $seed): float
    {
        return crc32($seed) / 4294967296;
    }
}
