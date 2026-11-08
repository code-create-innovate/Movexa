import { SVGAttributes } from 'react';

/** Movexa mark (original). */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" aria-hidden>
            <path d="M112 400V112h64l80 120 80-120h64v288h-64V224l-80 112-80-112v176z" />
        </svg>
    );
}
