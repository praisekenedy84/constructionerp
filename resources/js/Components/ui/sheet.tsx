import * as SheetPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Sheet = SheetPrimitive.Root;
const SheetTrigger = SheetPrimitive.Trigger;
const SheetClose = SheetPrimitive.Close;
const SheetPortal = SheetPrimitive.Portal;

const SheetOverlay = React.forwardRef<
    React.ElementRef<typeof SheetPrimitive.Overlay>,
    React.ComponentPropsWithoutRef<typeof SheetPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <SheetPrimitive.Overlay
        className={cn(
            'fixed inset-0 z-50 bg-slate-900/50 data-[state=open]:animate-sheet-overlay-in data-[state=closed]:animate-sheet-overlay-out',
            className,
        )}
        {...props}
        ref={ref}
    />
));
SheetOverlay.displayName = SheetPrimitive.Overlay.displayName;

const sheetVariants = cva(
    'fixed z-50 gap-4 bg-white shadow-lg outline-none dark:bg-slate-900',
    {
        variants: {
            side: {
                top: 'inset-x-0 top-0 border-b data-[state=open]:animate-sheet-in-from-top data-[state=closed]:animate-sheet-out-to-top',
                bottom: 'inset-x-0 bottom-0 border-t data-[state=open]:animate-sheet-in-from-bottom data-[state=closed]:animate-sheet-out-to-bottom',
                left: 'inset-y-0 left-0 h-full w-72 border-r data-[state=open]:animate-sheet-in-from-left data-[state=closed]:animate-sheet-out-to-left sm:max-w-sm',
                right: 'inset-y-0 right-0 h-full w-72 border-l data-[state=open]:animate-sheet-in-from-right data-[state=closed]:animate-sheet-out-to-right sm:max-w-sm',
            },
        },
        defaultVariants: {
            side: 'right',
        },
    },
);

interface SheetContentProps
    extends React.ComponentPropsWithoutRef<typeof SheetPrimitive.Content>,
        VariantProps<typeof sheetVariants> {}

const SheetContent = React.forwardRef<
    React.ElementRef<typeof SheetPrimitive.Content>,
    SheetContentProps
>(({ side = 'right', className, children, ...props }, ref) => (
    <SheetPortal>
        <SheetOverlay />
        <SheetPrimitive.Content ref={ref} className={cn(sheetVariants({ side }), className)} {...props}>
            {children}
            <SheetPrimitive.Close className="absolute right-4 top-4 rounded-md p-1 text-slate-500 opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 dark:text-slate-400">
                <X className="h-4 w-4" />
                <span className="sr-only">Close</span>
            </SheetPrimitive.Close>
        </SheetPrimitive.Content>
    </SheetPortal>
));
SheetContent.displayName = SheetPrimitive.Content.displayName;

function SheetHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-col space-y-1.5 p-6 pr-12 text-left', className)} {...props} />;
}

function SheetTitle({
    className,
    ...props
}: React.ComponentPropsWithoutRef<typeof SheetPrimitive.Title>) {
    return (
        <SheetPrimitive.Title
            className={cn('text-sm font-semibold text-slate-900 dark:text-white', className)}
            {...props}
        />
    );
}

function SheetDescription({
    className,
    ...props
}: React.ComponentPropsWithoutRef<typeof SheetPrimitive.Description>) {
    return (
        <SheetPrimitive.Description
            className={cn('text-xs text-slate-500 dark:text-slate-400', className)}
            {...props}
        />
    );
}

export {
    Sheet,
    SheetPortal,
    SheetOverlay,
    SheetTrigger,
    SheetClose,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
};
