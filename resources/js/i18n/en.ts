import type ar from './ar';

const en: typeof ar = {
    app: 'Movexa', home: 'Home', search: 'Search', live: 'Live TV', myList: 'My List', history: 'History', admin: 'Admin',
    backToHomecore: 'Back to HomeCore', switchProfile: 'Switch profile', settings: 'Settings', logout: 'Log out', language: 'العربية',
    whoIsWatching: "Who's watching?", manageProfiles: 'Manage profiles', addProfile: 'Add profile', kid: 'Kid', pin: 'PIN', enterPin: 'Enter PIN',
    parentPin: 'Parental PIN (or account password)', enter: 'Enter', cancel: 'Cancel', save: 'Save', delete: 'Delete', name: 'Name', maxAge: 'Max age rating',
    lockedKid: 'You are in a kid profile. Leaving requires the parental PIN.', done: 'Done', edit: 'Edit', optional: 'optional',
    rows: { continue: 'Continue watching', next_episode: 'Next episode', my_list: 'My List', recent: 'Recently added', genre: '' },
    play: 'Play', resume: 'Resume', moreInfo: 'More info', addToList: 'My List', inList: 'In my list', like: 'Like', love: 'Love it', dislike: 'Not for me',
    seasons: 'Seasons', season: 'Season', episode: 'Episode', episodes: 'Episodes', cast: 'Cast', audio: 'Audio', subtitles: 'Subtitles', off: 'Off',
    sanitized: 'Sanitized', emptyLibrary: 'The library is empty. An admin adds content from the admin panel.', noResults: 'No results',
    searchPlaceholder: 'Search movies or shows…', filters: 'Filters', allTypes: 'All', movies: 'Movies', shows: 'Shows', genre: 'Genre', year: 'Year', minRating: 'Min rating',
    watchParty: 'Watch party', copyLink: 'Copy link', copied: 'Copied', chat: 'Chat', send: 'Send', members: 'Watching',
    player: {
        preparing: 'Preparing video…', quality: 'Quality', auto: 'Auto', original: 'Original', speed: 'Speed', skipIntro: 'Skip intro',
        nextIn: 'Next episode in', cancel: 'Cancel', playNow: 'Play now', pip: 'Picture in picture', fullscreen: 'Fullscreen', subStyle: 'Subtitle style',
        size: 'Size', color: 'Color', background: 'Background', position: 'Position', uploadSub: 'Upload subtitle', limit: 'Concurrent stream limit reached',
        method: { direct: 'Direct play', remux: 'Remux', transcode: 'Transcode' }, error: 'Playback failed', back: 'Back',
    },
    liveTv: {
        now: 'Now', next: 'Next', favorites: 'Favorites', all: 'All', reconnecting: 'Reconnecting…', latency: 'Latency', viewers: 'viewers',
        tunerLimit: 'All tuners are busy right now', noChannels: 'No channels. An admin adds a licensed source in the admin panel.', schedule: 'Schedule',
        categories: { sports: 'Sports', news: 'News', kids: 'Kids', movies: 'Movies', music: 'Music', documentary: 'Documentary' },
    },
    adminUi: {
        dashboard: 'Dashboard', libraries: 'Libraries', addLibrary: 'Add library', path: 'Path on disk', source: 'Source', scan: 'Scan',
        upload: 'Upload files', uploading: 'Uploading', quarantine: 'Quarantine & security', matches: 'Fix matches', liveSources: 'Live sources',
        users: 'Users', sessions: 'Watching now', transcodes: 'Transcode queue', storage: 'Storage', encoder: 'Encoder', scanner: 'Virus scanner',
        topWatched: 'Most watched', retry: 'Retry', purge: 'Delete permanently', reason: 'Reason', threat: 'Threat', status: 'Status',
        securityNote: '100% protection is technically impossible. These layers (type check, ClamAV, rebuild, isolation, sandbox) reduce the risk to a practical minimum. There is no option to allow an infected file as-is.',
        legalNote: 'You are responsible for the legality of this source. Use only licensed IPTV subscriptions or a tuner you own.', legalAck: 'I confirm this source is legal and licensed to me',
        refresh: 'Refresh', record: 'Record', recordings: 'Recordings', pretranscode: 'Pre-transcode', pinned: 'pinned', events: 'Security log', recent: 'Recent files',
        maxStreams: 'Stream limit', admin: 'Admin', rematch: 'Fix', intro: 'Intro (seconds)', cpu: 'CPU load', available: 'available', unavailable: 'unavailable',
        securityUpdates: 'Security updates available',
    },
    twoFactor: { title: 'Two-factor authentication', enable: 'Enable', disable: 'Disable', enabled: 'Enabled', scan: 'Add this key to your authenticator app, then enter the code', code: 'Verification code', password: 'Password', verify: 'Verify' },
};
export default en;
