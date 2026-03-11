import { Head } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { books, dashboard, members, timeInOut } from '@/routes';
import StatsCard from '@/components/stats-card';
import React from 'react';
import { AppSidebar } from '@/components/app-sidebar';
import {LucideLogs, LucideUser, LucideBook,} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-react';

interface DashboardProps {
    booksCount?: number;
    membersCount?: number;
    today?: number;
    weeklyCount?: number;
    lifetimeCount?: number;
    dailyCount?: number;
    books: Array<{
        id: number;
        title: string;
    }>; 
}
interface StatsData {
    [period: string]: {
        mainCounter?: number;
        mainLabel?: string;
        booksCounter?: number;
        booksLabel?: string;
        membersCounter?: number;
        membersLabel? : string;
    };
}
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard({ booksCount = 0, membersCount = 0, dailyCount = 0, weeklyCount = 0, lifetimeCount = 0 }: DashboardProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <StatsCard
                    title="Stats Overview"
                    icon={LucideLogs}
                    quantity={booksCount + membersCount}
                    
                />
            </div>
        </AppLayout>
    );
}
