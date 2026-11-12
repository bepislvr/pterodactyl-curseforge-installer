import React, { useState } from 'react';
import http from '@/api/http';
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

const base = (uuid: string) => `/api/client/extensions/modpackinstaller/servers/${uuid}`;

const errorMessage = (e: any): string => e?.response?.data?.error || e?.message || 'Something went wrong.';

const ModpackInstaller = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [query, setQuery] = useState('');
    const [resolved, setResolved] = useState<Resolved | null>(null);
    const [selected, setSelected] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    const lookup = async () => {
        setBusy(true);
        setError(null);
        setDone(null);
        setResolved(null);
        try {
            const { data } = await http.post(`${base(uuid)}/resolve`, { query });
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
            const { data } = await http.post(`${base(uuid)}/install`, {
                mod_id: resolved.modpack.id,
                server_pack_file_id: selected,
            });
            setDone(`Installed ${data.file}. Check the Files tab, then restart or reinstall the server as your egg requires.`);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ServerContentBlock title={'Modpack Installer'}>
            <div className={'mb-4'}>
                <p className={'text-sm text-neutral-300 mb-2'}>
                    Paste a CurseForge modpack URL, slug or project ID. Only modpacks with an official server pack are supported.
                </p>
                <input
                    className={'w-full p-2 rounded bg-neutral-900 text-neutral-100'}
                    placeholder={'https://www.curseforge.com/minecraft/modpacks/...'}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <div className={'mt-2'}>
                    <Button disabled={busy || query.trim() === ''} onClick={lookup}>
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
