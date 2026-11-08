import type { Config } from 'ziggy-js';

export interface User { id: number; name: string; email: string; email_verified_at?: string | null; is_admin: boolean; locale: string; avatar?: string }
export interface Profile { id: number; name: string; avatar_color: string; is_kid: boolean; max_age_rating: number | null; locale: string | null; has_pin?: boolean; subtitle_prefs?: SubPrefs | null; settings?: ProfileSettings }
export interface ProfileSettings { intro: boolean; pause_screen: boolean; ambient: 'off' | 'soft' | 'strong'; poster_wall: boolean; recap: boolean }
export interface SubPrefs { size: number; color: string; background: number; position: number }
export interface Card {
    id: number; type: 'movie' | 'show'; title: string; year: number | null; age_rating: number | null; vote: number | null; genres: string[];
    poster: string | null; backdrop: string | null; progress?: number; file_id?: number; episode?: { season: number; number: number } | null; overview?: string;
}
export interface SharedData {
    viewAs?: boolean;
    name: string; locale: 'ar' | 'en'; auth: { user: User | null }; profile: Profile | null;
    homecore: { url: string | null; sso: boolean; theme: string | null };
    reverb: { key: string; host: string; port: number; scheme: string } | null;
    ziggy: Config & { location: string }; [key: string]: unknown;
}
// Legacy starter-kit types (settings pages)
export interface Auth { user: User }
export interface BreadcrumbItem { title: string; href: string }
export interface NavItem { title: string; url: string; icon?: any; isActive?: boolean }
export interface NavGroup { title: string; items: NavItem[] }
