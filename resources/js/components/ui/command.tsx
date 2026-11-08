import { Command as CommandPrimitive } from 'cmdk';
import { Search } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

const Command = ({ className, ...p }: React.ComponentProps<typeof CommandPrimitive>) => (
    <CommandPrimitive className={cn('bg-popover text-popover-foreground flex h-full w-full flex-col overflow-hidden rounded-lg', className)} {...p} />
);
const CommandInput = ({ className, ...p }: React.ComponentProps<typeof CommandPrimitive.Input>) => (
    <div className="flex items-center gap-2 border-b px-3">
        <Search className="size-4 shrink-0 opacity-50" />
        <CommandPrimitive.Input dir="auto" className={cn('placeholder:text-muted-foreground flex h-12 w-full bg-transparent py-3 text-base outline-none', className)} {...p} />
    </div>
);
const CommandList = ({ className, ...p }: React.ComponentProps<typeof CommandPrimitive.List>) => <CommandPrimitive.List className={cn('max-h-[60vh] overflow-y-auto p-1', className)} {...p} />;
const CommandEmpty = (p: React.ComponentProps<typeof CommandPrimitive.Empty>) => <CommandPrimitive.Empty className="text-muted-foreground py-6 text-center text-sm" {...p} />;
const CommandGroup = ({ className, ...p }: React.ComponentProps<typeof CommandPrimitive.Group>) => (
    <CommandPrimitive.Group className={cn('[&_[cmdk-group-heading]]:text-muted-foreground p-1 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs', className)} {...p} />
);
const CommandItem = ({ className, ...p }: React.ComponentProps<typeof CommandPrimitive.Item>) => (
    <CommandPrimitive.Item className={cn('data-[selected=true]:bg-accent relative flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm outline-none select-none', className)} {...p} />
);
export { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList };
