import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import chart from 'chart.js/auto';
import React from 'react';
import { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-react';

interface StatsCardProps {
    title: string;
    description?: string;
    quantity: number;
    data?: StatsData;
    icon?: LucideIcon;
    itemName?: string;
    fullName?: string;
    municipality?: string;
    province?: string;
    contactNumber?: number;
    memberdate?: Date;
}

interface StatsData{
    [period: string]: {
        booksCounter?: number;
        membersCounter?: number;
    };
}

export default function StatsCard({ title, description, quantity, data, icon }: StatsCardProps) {
    const booksdata = {
        labels: Object.keys(data || {}),
        datasets: [
            {
                label: 'Books Added',
                data: Object.values(data || {}).map((entry) => entry.booksCounter || 0),
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1,
            },
        ],
    };
    
    const membersdata = {
        labels: Object.keys(data || {}),
        datasets: [
            {
                label: 'Members Added',
                data: Object.values(data || {}).map((entry) => entry.membersCounter || 0),
                backgroundColor: 'rgba(153, 102, 255, 0.2)',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 1,
            },
        ],
    };
    return (
        <Card className="rounded-xl">
            <CardHeader className="px-4 pt-4 pb-0">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-lg flex items-center gap-2">
                        {icon && React.createElement(icon, { className: "h-5 w-5" })}
                        {title}
                    </CardTitle>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-8 w-8">
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem>View Details</DropdownMenuItem>
                            <DropdownMenuItem>Export Data</DropdownMenuItem>
                            <DropdownMenuItem>Refresh</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                {description && (
                    <CardDescription className="text-center mt-2">{description}</CardDescription>
                )}
            </CardHeader>
        </Card>
    );
} 
