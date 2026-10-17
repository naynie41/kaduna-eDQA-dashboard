import { Deferred, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { useAnnounce } from "@/components/Announcer";
import FacilityDrawer from "@/components/FacilityDrawer";
import FacilityTable from "@/components/FacilityTable";
import Pagination from "@/components/Pagination";
import Panel from "@/components/Panel";
import SegmentedControl from "@/components/SegmentedControl";
import Skeleton from "@/components/Skeleton";
import { formatCount } from "@/lib/format";
import { useTranslate, type Translate } from "@/lib/i18n";
import type {
    FacilityProps,
    FacilityRow,
    FacilitySortColumn,
    FacilityTableState,
    Metric,
} from "@/types/dashboard";

const METRICS: Metric[] = [
    "availability",
    "consistency",
    "validity",
    "overall",
];
const TEXT_COLUMNS: FacilitySortColumn[] = ["facility", "lga", "ward", "owner"];

function isTableState(value: unknown): value is FacilityTableState {
    return (
        typeof value === "object" &&
        value !== null &&
        "page" in value &&
        "sort" in value &&
        "metric" in value
    );
}

/**
 * Facility view (ARCHITECTURE.md §9.2.2): the measure switcher, the server-sorted, server-paged
 * table and the weakest-first note. Measure, sort and page live in the URL (?metric= ?sort= ?dir=
 * ?page=) and change through partial reloads of `facilities` only: the shell, the filters and
 * focus stay as they are, and the new order or page is announced.
 *
 * A facility name opens its detail drawer (§9.2.7). The open facility is ?facility={id}, changed
 * on the client (router.replace, no request), so the link can be shared and opens the drawer.
 */
export default function Facility({ facilities }: FacilityProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const { url } = usePage();
    const [path, search = ""] = url.split("?");
    const params = new URLSearchParams(search);
    const openId = Number(params.get("facility"));
    const [openedName, setOpenedName] = useState<string | undefined>(undefined);

    const withFacility = (id: number | null) => {
        const query = new URLSearchParams(search);
        if (id === null) {
            query.delete("facility");
        } else {
            query.set("facility", String(id));
        }
        const next = query.toString();

        return next === "" ? (path ?? url) : `${path ?? url}?${next}`;
    };
    const openFacility = (row: FacilityRow) => {
        setOpenedName(row.name);
        router.replace({
            url: withFacility(row.facilityId),
            preserveScroll: true,
            preserveState: true,
        });
    };
    const closeFacility = () => {
        setOpenedName(undefined);
        router.replace({
            url: withFacility(null),
            preserveScroll: true,
            preserveState: true,
        });
    };

    const reload = (
        changes: Record<string, string | null>,
        message: (state: FacilityTableState) => string,
    ) => {
        const query = new URLSearchParams(search);
        for (const [key, value] of Object.entries(changes)) {
            if (value === null) {
                query.delete(key);
            } else {
                query.set(key, value);
            }
        }
        router.get(path ?? url, Object.fromEntries(query), {
            only: ["facilities"],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => {
                const next = page.props.facilities;
                if (isTableState(next)) {
                    announce(message(next));
                }
            },
        });
    };

    const changeMetric = (metric: Metric) =>
        reload(
            {
                metric: metric === "overall" ? null : metric,
                sort: null,
                dir: null,
                page: null,
            },
            (state) => sortedMessage(t, state),
        );

    const sort = (column: FacilitySortColumn) => {
        const current = facilities?.sort;
        const direction =
            current?.column === column && current.direction === "asc"
                ? "desc"
                : "asc";
        reload({ sort: column, dir: direction, page: null }, (state) =>
            sortedMessage(t, state),
        );
    };

    const goTo = (page: number) =>
        reload({ page: page === 1 ? null : String(page) }, (state) =>
            t("dashboard.facility.paged", {
                page: formatCount(state.page.page),
                ...range(state),
            }),
        );

    return (
        <div className="flex flex-col gap-3">
            <Panel>
                <div className="flex flex-wrap items-start gap-4 px-5 pt-4">
                    <div className="min-w-56 flex-1">
                        <h2 className="text-[15.5px]">
                            {t("dashboard.facility.table_title")}
                        </h2>
                        <p className="mt-0.5 max-w-[70ch] text-[12.5px] text-ink-2">
                            {t("dashboard.pages.facility.subtitle", {
                                count: formatCount(facilities?.page.total ?? 0),
                            })}
                        </p>
                    </div>
                    <SegmentedControl
                        label={t("dashboard.facility.metric")}
                        value={facilities?.metric ?? "overall"}
                        onChange={changeMetric}
                        options={METRICS.map((metric) => ({
                            value: metric,
                            label:
                                metric === "overall"
                                    ? t("dashboard.facility.overall")
                                    : t(`enums.dimension.${metric}`),
                        }))}
                    />
                </div>
                <div className="mt-3">
                    <Deferred
                        data="facilities"
                        fallback={<Skeleton className="h-[640px] w-full" />}
                    >
                        {facilities !== undefined && (
                            <>
                                <FacilityTable
                                    state={facilities}
                                    caption={t("dashboard.facility.caption", {
                                        order: orderText(t, facilities),
                                    })}
                                    onSort={sort}
                                    onOpen={openFacility}
                                />
                                <Pagination
                                    page={facilities.page.page}
                                    lastPage={facilities.page.lastPage}
                                    from={facilities.page.from}
                                    to={facilities.page.to}
                                    total={facilities.page.total}
                                    onPage={goTo}
                                />
                            </>
                        )}
                    </Deferred>
                </div>
            </Panel>
            <p className="max-w-[78ch] text-[12.5px] text-ink-2">
                {t("dashboard.facility.weakest_note")}
            </p>
            {Number.isInteger(openId) && openId > 0 && (
                <FacilityDrawer
                    key={openId}
                    facilityId={openId}
                    name={openedName}
                    year={params.get("year") ?? undefined}
                    quarter={params.get("quarter") ?? undefined}
                    onClose={closeFacility}
                />
            )}
        </div>
    );
}

function columnLabel(t: Translate, state: FacilityTableState): string {
    const column = state.sort.column;
    if (column === "score" || column === "overall") {
        return state.metric === "overall"
            ? t("dashboard.facility.overall")
            : t(`enums.dimension.${state.metric}`);
    }
    if (column === "m1" || column === "m2" || column === "m3") {
        return t("common.month_slot", { slot: column.slice(1) });
    }

    return t(`dashboard.facility.${column}`);
}

/** "lowest first" for a score, "A to Z" for a name. */
function directionText(t: Translate, state: FacilityTableState): string {
    const text = TEXT_COLUMNS.includes(state.sort.column);
    const key =
        state.sort.direction === "asc"
            ? text
                ? "text_ascending"
                : "ascending"
            : text
              ? "text_descending"
              : "descending";

    return t(`dashboard.facility.${key}`);
}

function orderText(t: Translate, state: FacilityTableState): string {
    return t("dashboard.facility.order", {
        column: columnLabel(t, state),
        direction: directionText(t, state),
    });
}

function range(state: FacilityTableState): {
    from: string;
    to: string;
    total: string;
} {
    const { from, to, total } = state.page; // the server's figures

    return {
        from: formatCount(from),
        to: formatCount(to),
        total: formatCount(total),
    };
}

function sortedMessage(t: Translate, state: FacilityTableState): string {
    return t("dashboard.facility.sorted", {
        column: columnLabel(t, state),
        direction: directionText(t, state),
        ...range(state),
    });
}
