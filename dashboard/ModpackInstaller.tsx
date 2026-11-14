import React, { useCallback, useEffect, useRef, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { Button } from '@/components/elements/button/index';

// CurseForge API terms: nothing obtained through the API may be saved or cached. This page keeps results in
// component state only (gone when the page is left). Do not add localStorage, sessionStorage, IndexedDB or a
// client-side cache here, and do not add automatic retries or requests that are not started by the user.

interface Version {
    fileId: number;
    serverPackFileId: number;
    displayName: string;
    gameVersions: string[];
    date: string;
}

interface Resolved {
    modpack: { id: number; name: string; slug: string; authors: string[]; projectUrl: string | null };
    versions: Version[];
}

interface SearchResult {
    id: number;
    name: string;
    slug: string;
    summary: string;
    thumbnailUrl: string | null;
    downloadCount: number;
    hasServerPack: boolean;
    authors: string[];
    projectUrl: string | null;
    distributionDisabled: boolean;
}

interface SearchPage {
    results: SearchResult[];
    index: number;
    pageSize: number;
    totalCount: number;
    hasMore: boolean;
}

// An error message, with the project's CurseForge page when the user has to download the pack there by hand.
interface Problem {
    message: string;
    projectUrl?: string | null;
}

const PAGE_SIZE = 20;
const DEBOUNCE_MS = 400;
// Typing searches on its own only from this many characters; shorter searches need the Search button.
const MIN_AUTO_SEARCH_CHARS = 3;

const DISTRIBUTION_DISABLED =
    'The author of this modpack has disabled downloads through third-party tools. ' +
    'Download the server pack manually from its CurseForge page.';

const base = (uuid: string) => `/api/client/extensions/modpackinstaller/servers/${uuid}`;

// The extension returns {"error": "..."}; panel middleware (403, validation, throttling) returns {"errors": [{detail}]}.
// httpErrorToHuman handles both.
const errorMessage = (e: any): string => httpErrorToHuman(e) || 'Something went wrong.';

const problemFrom = (e: any): Problem => {
    const url = e?.response?.data?.projectUrl;
    return { message: errorMessage(e), projectUrl: typeof url === 'string' ? url : null };
};

// Only ever link to an https page on curseforge.com (the backend already enforces this).
const safeProjectUrl = (url: string | null | undefined): string | null =>
    typeof url === 'string' && /^https:\/\/([a-z0-9-]+\.)*curseforge\.com\//i.test(url) ? url : null;

// The shared axios instance times out after 20s, but /install blocks until Wings has downloaded and extracted the
// pack. Match the backend's set_time_limit(900). The web server's own timeout (nginx fastcgi_read_timeout) still applies.
const INSTALL_TIMEOUT_MS = 15 * 60 * 1000;

const ProjectLink = ({ url }: { url: string | null | undefined }) => {
    const href = safeProjectUrl(url);
    if (!href) return null;
    return (
        <a
            href={href}
            target={'_blank'}
            rel={'noopener noreferrer'}
            className={'text-blue-400 hover:underline'}
            onClick={(e) => e.stopPropagation()}
        >
            View on CurseForge
        </a>
    );
};

const ProblemMessage = ({ problem, className }: { problem: Problem; className?: string }) => (
    <p role={'alert'} className={`text-red-400 ${className ?? ''}`}>
        {problem.message} <ProjectLink url={problem.projectUrl} />
    </p>
);

const ModpackInstaller = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [query, setQuery] = useState('');

    // Search state. `searchText` is the raw input, `lastSearch` the text of the search whose results are shown.
    const [searchText, setSearchText] = useState('');
    const [lastSearch, setLastSearch] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [hasMore, setHasMore] = useState(false);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<Problem | null>(null);
    const [searched, setSearched] = useState(false);
    const searchSeq = useRef(0);
    // The search request that is on its way, as "text|index". An identical request is not sent a second time.
    const inFlight = useRef<string | null>(null);
    const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [resolved, setResolved] = useState<Resolved | null>(null);
    const [selected, setSelected] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<Problem | null>(null);
    const [done, setDone] = useState<string | null>(null);

    // Fetch a page of results. `append` is true for "Load more". Older in-flight responses are ignored.
    const runSearch = useCallback(
        async (text: string, index: number, append: boolean) => {
            const key = `${text}|${index}`;
            if (inFlight.current === key) return;
            inFlight.current = key;
            const seq = ++searchSeq.current;
            setSearching(true);
            setSearchError(null);
            try {
                const { data } = await http.post<SearchPage>(`${base(uuid)}/search`, {
                    query: text,
                    index,
                    page_size: PAGE_SIZE,
                });
                if (seq !== searchSeq.current) return;
                setResults((prev) => (append ? [...prev, ...data.results] : data.results));
                setHasMore(data.hasMore);
                setLastSearch(text);
                setSearched(true);
            } catch (e) {
                if (seq !== searchSeq.current) return;
                // No automatic retry: the user decides when to search again (for example after a rate limit).
                setSearchError(problemFrom(e));
                if (!append) setResults([]);
                setHasMore(false);
            } finally {
                if (inFlight.current === key) inFlight.current = null;
                if (seq === searchSeq.current) setSearching(false);
            }
        },
        [uuid]
    );

    // Typing searches after a pause, and only from MIN_AUTO_SEARCH_CHARS characters. Nothing is requested when the
    // page opens: every CurseForge request starts with something the user did.
    useEffect(() => {
        const text = searchText.trim();
        if (text.length < MIN_AUTO_SEARCH_CHARS) return;
        const t = setTimeout(() => runSearch(text, 0, false), DEBOUNCE_MS);
        debounceTimer.current = t;
        return () => clearTimeout(t);
    }, [searchText, runSearch]);

    // The Search button (or Enter) searches whatever is typed, also fewer than three characters. With an empty
    // box it lists the most popular modpacks.
    const searchNow = () => {
        // Cancel the pending typed search, so pressing Enter does not send the same search twice.
        if (debounceTimer.current) clearTimeout(debounceTimer.current);
        runSearch(searchText.trim(), 0, false);
    };

    const lookup = async (reference: string = query) => {
        setBusy(true);
        setError(null);
        setDone(null);
        setResolved(null);
        try {
            const { data } = await http.post(`${base(uuid)}/resolve`, { query: reference });
            setResolved(data);
            setSelected(data.versions[0]?.serverPackFileId ?? null);
        } catch (e) {
            setError(problemFrom(e));
        } finally {
            setBusy(false);
        }
    };

    const pick = (r: SearchResult) => {
        if (busy) return;
        if (r.distributionDisabled) {
            // Already known from the search result: no further request is made for this pack.
            setResolved(null);
            setDone(null);
            setError({ message: DISTRIBUTION_DISABLED, projectUrl: r.projectUrl });
            return;
        }
        setQuery(String(r.id));
        lookup(String(r.id));
    };

    const install = async () => {
        if (!resolved || selected === null) return;
        const ok = window.confirm(
            'This extracts the server pack into your server root and may overwrite existing files. ' +
                'Make a backup first. Continue?'
        );
        if (!ok) return;

        setBusy(true);
        setError(null);
        try {
            const { data } = await http.post(
                `${base(uuid)}/install`,
                { mod_id: resolved.modpack.id, server_pack_file_id: selected },
                { timeout: INSTALL_TIMEOUT_MS }
            );
            setDone(`Installed ${data.file}. Check the Files tab, then restart or reinstall the server as your egg requires.`);
        } catch (e: any) {
            const status = e?.response?.status;
            if (status === 502 || status === 504 || e?.code === 'ECONNABORTED') {
                // The web server (or the browser) stopped waiting, but the panel keeps running the install on the node.
                setError({
                    message:
                        'The request timed out before the install finished. It may still be running on the node: ' +
                        'check the Files tab in a few minutes before trying again.',
                });
            } else {
                setError(problemFrom(e));
            }
        } finally {
            setBusy(false);
        }
    };

    const tooShort = searchText.trim().length > 0 && searchText.trim().length < MIN_AUTO_SEARCH_CHARS;

    return (
        <ServerContentBlock title={'Modpack Installer'}>
            <div className={'mb-6'}>
                <p className={'text-sm text-neutral-300 mb-2'}>
                    Search CurseForge modpacks, or paste a URL, slug or project ID below. Only modpacks with an
                    official server pack are supported.
                </p>
                <div className={'flex'}>
                    <input
                        className={'flex-1 p-2 rounded bg-neutral-900 text-neutral-100'}
                        placeholder={'Search modpacks...'}
                        aria-label={'Search modpacks'}
                        value={searchText}
                        onChange={(e) => setSearchText(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') searchNow();
                        }}
                    />
                    <div className={'ml-2'}>
                        <Button disabled={searching} onClick={searchNow}>
                            {searching ? 'Searching...' : searchText.trim() === '' ? 'Show popular' : 'Search'}
                        </Button>
                    </div>
                </div>
                {tooShort && !searching && (
                    <p className={'text-sm text-neutral-400 mt-2'}>
                        Type at least {MIN_AUTO_SEARCH_CHARS} characters, or press Search.
                    </p>
                )}
                {searchError && <ProblemMessage problem={searchError} className={'mt-2'} />}
                {!searchError && searching && results.length === 0 && (
                    <p className={'text-sm text-neutral-400 mt-2'}>Searching...</p>
                )}
                {!searchError && searched && !searching && results.length === 0 && (
                    <p className={'text-sm text-neutral-400 mt-2'}>No modpacks found.</p>
                )}
                {results.length > 0 && (
                    <ul className={'mt-2'}>
                        {results.map((r) => (
                            <li
                                key={r.id}
                                className={'flex items-start p-2 mb-1 rounded bg-neutral-900 cursor-pointer hover:bg-neutral-800'}
                                role={'button'}
                                tabIndex={0}
                                onClick={() => pick(r)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && e.target === e.currentTarget) pick(r);
                                }}
                            >
                                {r.thumbnailUrl ? (
                                    <img src={r.thumbnailUrl} alt={''} className={'w-12 h-12 rounded mr-3 flex-none'} />
                                ) : (
                                    <div className={'w-12 h-12 rounded mr-3 flex-none bg-neutral-700'} />
                                )}
                                <div className={'min-w-0'}>
                                    <div className={'text-neutral-100'}>
                                        {r.name}
                                        {r.hasServerPack && !r.distributionDisabled && (
                                            <span className={'ml-2 text-xs text-green-400'}>server pack</span>
                                        )}
                                        {r.distributionDisabled && (
                                            <span className={'ml-2 text-xs text-yellow-400'}>manual download only</span>
                                        )}
                                    </div>
                                    {r.authors.length > 0 && (
                                        <div className={'text-xs text-neutral-300'}>by {r.authors.join(', ')}</div>
                                    )}
                                    <div className={'text-sm text-neutral-400'}>{r.summary}</div>
                                    <div className={'text-xs text-neutral-500'}>
                                        {r.downloadCount.toLocaleString()} downloads <ProjectLink url={r.projectUrl} />
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {hasMore && (
                    <div className={'mt-2'}>
                        <Button disabled={searching} onClick={() => runSearch(lastSearch, results.length, true)}>
                            {searching ? 'Loading...' : 'Load more'}
                        </Button>
                    </div>
                )}
            </div>
            <div className={'mb-4'}>
                <p className={'text-sm text-neutral-300 mb-2'}>Or paste a CurseForge modpack URL, slug or project ID.</p>
                <input
                    className={'w-full p-2 rounded bg-neutral-900 text-neutral-100'}
                    placeholder={'https://www.curseforge.com/minecraft/modpacks/...'}
                    aria-label={'CurseForge modpack URL, slug or project ID'}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <div className={'mt-2'}>
                    <Button disabled={busy || query.trim() === ''} onClick={() => lookup()}>
                        {busy ? 'Working...' : 'Look up'}
                    </Button>
                </div>
            </div>

            {error && <ProblemMessage problem={error} className={'mb-4'} />}
            {done && <p className={'text-green-400 mb-4'}>{done}</p>}

            {resolved && (
                <div>
                    <h3 className={'text-lg'}>{resolved.modpack.name}</h3>
                    <p className={'text-sm text-neutral-300 mb-2'}>
                        {resolved.modpack.authors.length > 0 && <>by {resolved.modpack.authors.join(', ')} </>}
                        <ProjectLink url={resolved.modpack.projectUrl} />
                    </p>
                    <select
                        className={'w-full p-2 rounded bg-neutral-900 text-neutral-100'}
                        aria-label={'Server pack version'}
                        value={selected ?? ''}
                        onChange={(e) => setSelected(Number(e.target.value))}
                    >
                        {resolved.versions.map((v) => (
                            <option key={v.serverPackFileId} value={v.serverPackFileId}>
                                {v.displayName} ({v.gameVersions.join(', ')})
                            </option>
                        ))}
                    </select>
                    <div className={'mt-2'}>
                        <Button disabled={busy || selected === null} onClick={install}>
                            {busy ? 'Installing...' : 'Install server pack'}
                        </Button>
                    </div>
                </div>
            )}

            <p className={'text-xs text-neutral-500 mt-8'}>
                Modpack information and files come from CurseForge and belong to their authors. This extension is not
                affiliated with or endorsed by CurseForge or Overwolf.
            </p>
        </ServerContentBlock>
    );
};

export default ModpackInstaller;
