import { Form, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
	Dialog,
	DialogContent,
	DialogDescription,
	DialogHeader,
	DialogTitle,
} from '@/components/ui/dialog';
import { update } from '@/actions/App/Http/Controllers/Settings/PasswordController';

interface Book {
	id?: number;
	title: string;
	author: string;
	genre?: string;
	published_year?: number;
	availability?: number;
	cover_image?: string;
}

type Props = {
	isOpen: boolean;
	onClose: () => void;
	book?: Book | null;
	onSaved?: () => void; // callback after successful save/delete
};

export default function EditBookModal({
	isOpen,
	onClose,
	book,
	onSaved,
}: Props) {
	const [coverPreview, setCoverPreview] = useState<string | null>(null);
	const fileInputRef = useRef<HTMLInputElement>(null);

	const form = useForm({
		title: book?.title || '',
		author: book?.author || '',
		genre: book?.genre || '',
		published_year: book?.published_year || '',
		availability: book?.availability || 0,
		cover: null as File | null,
	});

	// When the book prop changes (opening modal with existing data), reset form state
	useEffect(() => {
		if (book) {
			form.setData({
				title: book.title || '',
				author: book.author || '',
				genre: book.genre || '',
				published_year: book.published_year || '',
				availability: book.availability || 0,
				cover: null,
			});
			setCoverPreview(book.cover_image ? `/${book.cover_image}` : null);
		} else {
			form.reset();
			setCoverPreview(null);
		}
	}, [book]);

	const handleFileChange = useCallback(
		(e: React.ChangeEvent<HTMLInputElement>) => {
			const file = e.target.files?.[0];
			if (!file) return;
			form.setData('cover', file);
			setCoverPreview(URL.createObjectURL(file));
		},
		[form],
	);

	const handleDrop = useCallback(
		(e: React.DragEvent<HTMLDivElement>) => {
			e.preventDefault();
			if (e.dataTransfer.files.length) {
				const file = e.dataTransfer.files[0];
				form.setData('cover', file);
				setCoverPreview(URL.createObjectURL(file));
			}
		},
		[form],
	);

	const submit = useCallback(
		(e: React.FormEvent) => {
			e.preventDefault();
			if (book && book.id) {
				form.post(`/books/${book.id}`, {
					headers: { 'X-HTTP-Method-Override': 'PUT' },
					onSuccess: () => {
						onSaved?.();
						onClose();
					},
				});
			} else {
				form.post('/books', {
					onSuccess: () => {
						onSaved?.();
						onClose();
					},
				});
			}
		},
		[form, book, onClose, onSaved],
	);

	const deleteBook = useCallback(() => {
		if (book && book.id) {
			router.delete(`/books/${book.id}`, {
				onSuccess: () => {
					onSaved?.();
					onClose();
				},
			});
		}
	}, [book, onClose, onSaved]);

	return (
		<Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
			<DialogContent className="max-w-lg">
				<DialogHeader>
					<DialogTitle>{book ? 'Edit Book' : 'Add Book'}</DialogTitle>
				</DialogHeader>
				<form onSubmit={submit} encType="multipart/form-data">
					{/* Cover Image Section */}
					<div className="form-section">
						<h3 className="section-title">
							<i className="fas fa-image" /> Book Cover
						</h3>
						<div
							id="cover-preview-area"
							className="border-2 border-dashed rounded-md p-8 text-center cursor-pointer bg-glass mb-6"
							onClick={() => fileInputRef.current?.click()}
							onDragOver={(e) => e.preventDefault()}
							onDrop={handleDrop}
						>
							<div id="cover-preview-content">
								{coverPreview ? (
									<img
										src={coverPreview}
										alt="cover preview"
										className="mx-auto max-h-40 object-contain"
									/>
								) : (
									<>
										<i
											id="cover-upload-icon"
											className="fas fa-cloud-upload-alt text-2xl text-muted"
										/>
										<p
											id="cover-preview-text"
											className="text-muted m-0 font-medium text-base"
										>
											Click or drag image here...
										</p>
										<small className="text-muted block mt-2">
											Supports JPG, PNG, GIF (max 5MB)
										</small>
									</>
								)}
								<input
									type="file"
									ref={fileInputRef}
									className="cover-input hidden"
									accept="image/*"
									onChange={handleFileChange}
								/>
							</div>
						</div>
					</div>
					{/* Book Information Section */}
					<div className="form-section">
						<h3 className="section-title">
							<i className="fas fa-book" /> Book Information
						</h3>
						<div className="form-grid">
							<div className="form-group">
								<label htmlFor="edit-title">Title *</label>
								<input
									type="text"
									id="edit-title"
									name="title"
									className="form-control"
									value={form.data.title}
									onChange={(e) =>
										form.setData('title', e.target.value)
									}
									required
								/>
							</div>
							<div className="form-group">
								<label htmlFor="edit-author">Author *</label>
								<input
									type="text"
									id="edit-author"
									name="author"
									className="form-control"
									value={form.data.author}
									onChange={(e) =>
										form.setData('author', e.target.value)
									}
									required
								/>
							</div>
							<div className="form-group">
								<label htmlFor="edit-genre">Genre</label>
								<input
									type="text"
									id="edit-genre"
									name="genre"
									className="form-control"
									value={form.data.genre}
									onChange={(e) =>
										form.setData('genre', e.target.value)
									}
								/>
							</div>
							<div className="form-group">
								<label htmlFor="edit-published-year">
									Published Year *
								</label>
								<input
									type="number"
									id="edit-published-year"
									name="published_year"
									className="form-control"
									min={1000}
									max={2099}
									required
									value={form.data.published_year}
									onChange={(e) =>
										form.setData(
											'published_year',
											Number(e.target.value),
										)
									}
								/>
							</div>
							<div className="form-group">
								<label htmlFor="edit-availability">
									Availability *
								</label>
								<input
									type="number"
									id="edit-availability"
									name="availability"
									className="form-control"
									min={0}
									required
									value={form.data.availability}
									onChange={(e) =>
										form.setData(
											'availability',
											Number(e.target.value),
										)
									}
								/>
							</div>
						</div>
					</div>
					<div className="modal-actions flex justify-end gap-2 mt-4">
						{book && book.id && (
							<Button
								variant="destructive"
								type="button"
								onClick={deleteBook}
							>
								Delete
							</Button>
						)}
						<Button
							variant="secondary"
							type="button"
							onClick={onClose}
						>
							Cancel
						</Button>
						<Button type="submit">Save</Button>
					</div>
				</form>
			</DialogContent>
		</Dialog>
	);
}
function useForm<T extends Record<string, any>>(initialData: T) {
    const [data, setData] = useState<T>(initialData);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

	const setFormData = (keyOrData: keyof T | Partial<T>, value?: any) => {
		if (typeof keyOrData === 'object') {
			setData((prev) => ({ ...prev, ...keyOrData }));
		} else {
			setData((prev) => ({ ...prev, [keyOrData]: value }));
		}
	};

    const reset = () => {
        setData(initialData);
        setErrors({});
    };

    const post = (
        url: string,
        options?: {
            headers?: Record<string, string>;
            onSuccess?: () => void;
            onError?: (errors: Record<string, string>) => void;
        },
    ) => {
        setProcessing(true);
        const formData = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        });

        fetch(url, {
            method: 'POST',
            headers: options?.headers,
            body: formData,
        })
            .then((res) => res.json())
            .then((response) => {
                if (response.errors) {
                    setErrors(response.errors);
                    options?.onError?.(response.errors);
                } else {
                    setErrors({});
                    options?.onSuccess?.();
                }
            })
            .catch((error) => console.error(error))
            .finally(() => setProcessing(false));
    };

    return {
        data,
        setData: setFormData,
        reset,
        post,
        processing,update,
        errors,
    };
}

