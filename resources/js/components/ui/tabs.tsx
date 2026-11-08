import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as React from 'react';
import { cn } from '@/lib/utils';

const Tabs = TabsPrimitive.Root;
const TabsList = ({ className, ...p }: React.ComponentProps<typeof TabsPrimitive.List>) => (
    <TabsPrimitive.List className={cn('bg-muted text-muted-foreground inline-flex h-10 items-center rounded-lg p-1', className)} {...p} />
);
const TabsTrigger = ({ className, ...p }: React.ComponentProps<typeof TabsPrimitive.Trigger>) => (
    <TabsPrimitive.Trigger
        className={cn('data-[state=active]:bg-background data-[state=active]:text-foreground inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-all focus-visible:outline-2 disabled:opacity-50', className)}
        {...p}
    />
);
const TabsContent = ({ className, ...p }: React.ComponentProps<typeof TabsPrimitive.Content>) => <TabsPrimitive.Content className={cn('mt-4', className)} {...p} />;

export { Tabs, TabsContent, TabsList, TabsTrigger };
