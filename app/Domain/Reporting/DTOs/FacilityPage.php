<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** One server-side page of the facility view (50 a page, §9.2.2). */
final readonly class FacilityPage
{
    /** @param  list<FacilityRow>  $rows */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
