import {
    ChevronDownIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronsUpDownIcon,
    ChevronUpIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

export type SortDir = 'asc' | 'desc';
export type SortState = { key: string; dir: SortDir } | null;

export type Column<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    sortable?: boolean;
    align?: 'left' | 'right';
    className?: string;
};

type DataTableProps<T> = {
    columns: Column<T>[];
    rows: T[];
    getRowId: (row: T) => string | number;
    /** Accessible caption describing the table for screen readers. */
    caption: string;
    sort?: SortState;
    onSort?: (key: string) => void;
    /** Rendered in place of the table when there are no rows. */
    empty?: ReactNode;
};

/**
 * Presentational, generic data table (SLO-15 CRUD building block). Sorting,
 * searching and pagination are owned by the page (server- or client-side); this
 * renders columns, sortable headers (with aria-sort) and rows.
 */
export default function DataTable<T>({
    columns,
    rows,
    getRowId,
    caption,
    sort,
    onSort,
    empty,
}: DataTableProps<T>) {
    if (rows.length === 0 && empty) {
        return <>{empty}</>;
    }

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
            <Table>
                <caption className="sr-only">{caption}</caption>
                <TableHeader>
                    <TableRow>
                        {columns.map((column) => {
                            const active = sort?.key === column.key;
                            const ariaSort = active
                                ? sort?.dir === 'asc'
                                    ? 'ascending'
                                    : 'descending'
                                : undefined;

                            return (
                                <TableHead
                                    key={column.key}
                                    aria-sort={ariaSort}
                                    className={cn(
                                        column.align === 'right' &&
                                            'text-right',
                                        column.className,
                                    )}
                                >
                                    {column.sortable && onSort ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(column.key)}
                                            className="inline-flex items-center gap-1 font-medium hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            {column.header}
                                            {active ? (
                                                sort?.dir === 'asc' ? (
                                                    <ChevronUpIcon className="size-3.5" />
                                                ) : (
                                                    <ChevronDownIcon className="size-3.5" />
                                                )
                                            ) : (
                                                <ChevronsUpDownIcon className="size-3.5 opacity-50" />
                                            )}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            );
                        })}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={getRowId(row)}>
                            {columns.map((column) => (
                                <TableCell
                                    key={column.key}
                                    className={cn(
                                        column.align === 'right' &&
                                            'text-right',
                                        column.className,
                                    )}
                                >
                                    {column.cell(row)}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

type PagerProps = {
    current: number;
    last: number;
    onPrev: () => void;
    onNext: () => void;
    prevLabel: string;
    nextLabel: string;
    statusLabel: string;
};

/** Simple prev/next pager (SLO-15 building block). */
export function Pager({
    current,
    last,
    onPrev,
    onNext,
    prevLabel,
    nextLabel,
    statusLabel,
}: PagerProps) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-sm text-muted-foreground">{statusLabel}</span>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={onPrev}
                    disabled={current <= 1}
                >
                    <ChevronLeftIcon className="size-4" />
                    {prevLabel}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={onNext}
                    disabled={current >= last}
                >
                    {nextLabel}
                    <ChevronRightIcon className="size-4" />
                </Button>
            </div>
        </div>
    );
}
