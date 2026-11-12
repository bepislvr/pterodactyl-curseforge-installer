import React, { useCallback, useEffect, useRef, useState } from 'react';
import http, { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { Button } from '@/components/elements/button/index';

interface Version {
    fileId: number;
    serverPackFileId: number;
    displayName: string;
    gameVersions: string[];
    date: string;
}

interface Resolved {
    modpack: { id: number; name: string; slug: string };
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
}

interface SearchPage {
    results: SearchResult[];
    index: number;
    pageSize: number;
    totalCount: number;
    hasMore: boolean;
}

const PAGE_SIZE = 20;
const DEBOUNCE_MS = 400;

const base = (uuid: string) => `/api/client/extensions/modpackinstaller/servers/${uuid}`;

// The extension returns {"error": "..."}; panel middleware (403, validation, throttling) returns {"errors": [{detail}]}.
// httpErrorToHuman handles both.
const errorMessage = (e: any): string => httpErrorToHuman(e) || 'Something went wrong.';

// The shared axios instance times out after 20s, but /install blocks until Wings has downloaded and extracted the
// pack. Match the backend's set_time_limit(900). The web server's own timeout (nginx fastcgi_read_timeout) still applies.
const INSTALL_TIMEOUT_MS = 15 * 60 * 1000;

const ModpackInstaller = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [query, setQuery] = useState('');

    // Search/browse state. `searchText` is the raw input, `activeSearch` the debounced value in use.
    const [searchText, setSearchText] = useState('');
    const [activeSearch, setActiveSearch] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [hasMore, setHasMore] = useState(false);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [searched, setSearched] = useState(false);
    const searchSeq = useRef(0);
    const [resolved, setResolved] = useState<Resolved | null>(null);
    const [selected, setSelected] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    // Debounce the search box.
    useEffect(() => {
        const t = setTimeout(() => setActiveSearch(searchText.trim()), DEBOUNCE_MS);
        return () => clearTimeout(t);
    }, [searchText]);

    // Fetch a page of results. `append` is true for "Load more". Older in-flight responses are ignored.
    const runSearch = useCallback(
        async (text: string, index: number, append: boolean) => {
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
                setSearched(true);
            } catch (e) {
                if (seq !== searchSeq.current) return;
                setSearchError(errorMessage(e));
                if (!append) setResults([]);
                setHasMore(false);
            } finally {
                if (seq === searchSeq.current) setSearching(false);
            }
        },
        [uuid]
    );

    // New search whenever the debounced text changes (also loads the popular list on first render).
    useEffect(() => {
        runSearch(activeSearch, 0, false);
    }, [activeSearch, runSearch]);

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
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
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
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ServerContentBlock title={'Modpack Installer'}>
            <div className={'mb-6'}>
                <p className={'text-sm text-neutral-300 mb-2'}>
                    Search CurseForge modpacks, or paste a URL, slug or project ID below. Only modpacks with an
                    official server pack are supported.
                </p>
                <input
                    className={'w-full p-2 rounded bg-neutral-900 text-neutral-100'}
                    placeholder={'Search modpacks...'}
                    value={searchText}
                    onChange={(e) => setSearchText(e.target.value)}
                />
                {searchError && <p className={'text-red-400 mt-2'}>{searchError}</p>}
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
                                onClick={() => {
                                    if (busy) return;
                                    setQuery(String(r.id));
                                    lookup(String(r.id));
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
                                        {r.hasServerPack && (
                                            <span className={'ml-2 text-xs text-green-400'}>server pack</span>
                                        )}
                                    </div>
                                    <div className={'text-sm text-neutral-400'}>{r.summary}</div>
                                    <div className={'text-xs text-neutral-500'}>
                                        {r.downloadCount.toLocaleString()} downloads
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {hasMore && (
                    <div className={'mt-2'}>
                        <Button disabled={searching} onClick={() => runSearch(activeSearch, results.length, true)}>
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
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <div className={'mt-2'}>
                    <Button disabled={busy || query.trim() === ''} onClick={() => lookup()}>
                        {busy ? 'Working...' : 'Look up'}
                    </Button>
                </div>
            </div>

            {error && <p className={'text-red-400 mb-4'}>{error}</p>}
            {done && <p className={'text-green-400 mb-4'}>{done}</p>}

            {resolved && (
                <div>
                    <h3 className={'text-lg mb-2'}>{resolved.modpack.name}</h3>
                    <select
                        className={'w-full p-2 rounded bg-neutral-900 text-neutral-100'}
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
        </ServerContentBlock>
    );
};

export default ModpackInstaller;
