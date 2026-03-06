import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

export type DataTableColumn<T> = {
    id?: string;
    header: ReactNode;
    headerClassName?: string;
    cellClassName?: string;
    cell: (row: T) => ReactNode;
};

export type DataTableProps<T> = {
    data: T[];
    columns: Array<DataTableColumn<T>>;
    rowKey?: (row: T, index: number) => string | number;
    empty?: ReactNode;
    className?: string;
};

export function DataTable<T>({
    data,
    columns,
    rowKey = (_row, index) => index,
    empty = <div className="p-4 text-sm text-muted-foreground">No results.</div>,
    className,
}: DataTableProps<T>) {
    return (
        <div className={cn('w-full', className)}>
            <Table>
                <TableHeader>
                    <TableRow>
                        {columns.map((col, i) => (
                            <TableHead key={col.id ?? i} className={col.headerClassName}>
                                {col.header}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>

                <TableBody>
                    {data.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={columns.length} className="p-0">
                                {empty}
                            </TableCell>
                        </TableRow>
                    ) : (
                        data.map((row, rowIndex) => (
                            <TableRow key={rowKey(row, rowIndex)}>
                                {columns.map((col, colIndex) => (
                                    <TableCell key={col.id ?? colIndex} className={col.cellClassName}>
                                        {col.cell(row)}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </Table>
        </div>
    );
}

