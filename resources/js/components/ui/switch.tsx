import * as SwitchPrimitive from '@radix-ui/react-switch';
import * as React from 'react';
import { cn } from '@/lib/utils';

function Switch({ className, ...props }: React.ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root className={cn('data-[state=checked]:bg-primary bg-input inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors', className)} {...props}>
            <SwitchPrimitive.Thumb className="bg-background block size-5 rounded-full shadow transition-transform data-[state=checked]:translate-x-5 data-[state=unchecked]:translate-x-0.5 rtl:data-[state=checked]:-translate-x-5 rtl:data-[state=unchecked]:-translate-x-0.5" />
        </SwitchPrimitive.Root>
    );
}
export { Switch };
