<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/**
 * One server-side page of the facility view (50 a page, §9.2.2), with the figures the pagination
 * shows ("Showing 51–100 of 2,184", the last page), so the browser does no arithmetic on them
 * (hard rule 5). from and to are 0 when the page has no rows.
 */
final readonly class FacilityPage
{
    public int $lastPage;

    public int $from;

    public int $to;

    /** @param  list<FacilityRow>  $rows */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->from = $total === 0 || $page > $this->lastPage ? 0 : ($page - 1) * $perPage + 1;
        $this->to = $this->from === 0 ? 0 : min($total, $page * $perPage);
    }
}
