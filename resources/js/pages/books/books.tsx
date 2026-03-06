import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import type { BreadcrumbItem } from '@/types';
import { books, dashboard } from '@/routes';
import EditBookModal from '@/components/modal-edit-books';
import { useState } from 'react';
import { Button } from '@/components/ui/button';   
import AppLogo from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';

interface Book {
    id: number;
    title: string;
    author: string;
    genre?: string;
    published_year?: number;
    availability?: number;
    qr_url?: string;
    cover_image?: string;
    created_at?: string;
    updated_at?: string;
}

interface Props {
    books: {
        data: Book[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    perPage: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'Books',
        href: books().url,
    },
];

export default function Books({ books }: Props) {
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedBook, setSelectedBook] = useState<Book | null>(null);

    const openEditModal = (book: Book) => {
        setSelectedBook(book);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setSelectedBook(null);
    };

    const columns: Array<DataTableColumn<Book>> = [
        { id: 'title', header: 'Title', cell: (b) => <span className="font-medium">{b.title}</span> },
        { id: 'author', header: 'Author', cell: (b) => b.author },
        { id: 'genre', header: 'Genre', cell: (b) => b.genre ?? <span className="text-muted-foreground">—</span> },
        {
            id: 'year',
            header: 'Year',
            cell: (b) => (b.published_year ? b.published_year : <span className="text-muted-foreground">—</span>),
        },
        {
            id: 'availability',
            header: 'Available',
            cell: (b) =>
                b.availability !== undefined ? b.availability : <span className="text-muted-foreground">—</span>,
        },
{
    id: 'actions',
    header: 'Actions',
    cell: (b) => (
        <div className="flex items-center gap-2">
            {b.qr_url && (
                <Button size="icon" variant="outline" style={{ backgroundColor: 'transparent' }} onClick={() => window.open(b.qr_url, '_blank')}>
                    <i className="fas fa-qrcode" style={{ backgroundColor: 'white' }} />
                </Button>
            )}
            <Button size="icon" variant="outline" onClick={() => alert('View details for ' + b.title)}>
                <i className="fas fa-eye" />
            </Button>
            <Button size="icon" variant="outline" onClick={() => openEditModal(b)}>
                <i className="fas fa-edit" />
            </Button>
        </div>
    ),
}

    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Books" />
            <div className="flex h-screen flex-col gap-4  rounded-xl p-4 overflow-hidden">
    {/* Header Section: Grouping Title and Button */}
    <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Books</h1>
        
        <Button onClick={() => openEditModal({
            id: 0,
            title: '', 
            author: '',
        })}>
            Add New Book
        </Button>
    </div>

    {/* Table and Pagination stay below because they are children of the main flex-col */}
        <div className="flow-1 rounded-md border overflow-auto">
            <DataTable data={books.data} columns={columns} rowKey={(b) => b.id} />
        </div>
    <div className="mt-4 text-sm text-gray-500">
        Showing page {books.current_page} of {books.last_page} ({books.total} total books)
    </div>
</div>
            <EditBookModal
                isOpen={modalOpen}
                onClose={closeModal}
                book={selectedBook}
                onSaved={() => {
                    window.location.reload();
                }}
            />
        </AppLayout>
    );
}
