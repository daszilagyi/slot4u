import type { FormEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

type FormSheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    submitLabel: string;
    cancelLabel: string;
    onSubmit: (event: FormEvent) => void;
    submitting?: boolean;
    children: ReactNode;
};

/**
 * Slide-over form container (SLO-15 CRUD building block). The page owns the
 * form fields and submit logic; this handles the shell, header and footer.
 */
export default function FormSheet({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    cancelLabel,
    onSubmit,
    submitting = false,
    children,
}: FormSheetProps) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                <SheetHeader className="border-b border-border">
                    <SheetTitle>{title}</SheetTitle>
                    {description ? (
                        <SheetDescription>{description}</SheetDescription>
                    ) : null}
                </SheetHeader>
                <form
                    onSubmit={onSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                        {children}
                    </div>
                    <SheetFooter className="flex-row justify-end gap-2 border-t border-border">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {cancelLabel}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitLabel}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
