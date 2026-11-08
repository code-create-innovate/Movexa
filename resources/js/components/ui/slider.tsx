import * as SliderPrimitive from '@radix-ui/react-slider';
import * as React from 'react';
import { cn } from '@/lib/utils';

function Slider({ className, ...props }: React.ComponentProps<typeof SliderPrimitive.Root>) {
    return (
        <SliderPrimitive.Root className={cn('relative flex w-full touch-none items-center select-none data-[disabled]:opacity-50', className)} {...props}>
            <SliderPrimitive.Track className="bg-muted relative h-1.5 w-full grow overflow-hidden rounded-full">
                <SliderPrimitive.Range className="bg-primary absolute h-full" />
            </SliderPrimitive.Track>
            {(props.value ?? props.defaultValue ?? [0]).map((_, i) => (
                <SliderPrimitive.Thumb key={i} className="border-primary bg-background block size-4 rounded-full border-2 shadow focus-visible:outline-2" />
            ))}
        </SliderPrimitive.Root>
    );
}
export { Slider };
