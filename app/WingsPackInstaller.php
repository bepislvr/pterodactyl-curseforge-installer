<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Has Wings download an archive into the server root and extract it.
 *
 * This class does not validate the URL. Callers must only pass URLs that passed
 * CurseForgeClient::isAllowedDownloadUrl() (the controller does).
 */
class WingsPackInstaller
{
    /** Seconds the panel waits for Wings to finish a foreground download. */
    public const PULL_TIMEOUT = 600;

    public function __construct(private DaemonFileRepository $files)
    {
    }

    /** @throws InstallException with a user-readable message */
    public function pullAndExtract(Server $server, string $url, string $fileName): void
    {
        $repo = $this->files->setServer($server);

        // DaemonFileRepository::pull() uses the panel's Guzzle timeout (GUZZLE_TIMEOUT, default 15s),
        // which is too short for a foreground download. Same Wings endpoint, per-request timeout.
        try {
            $repo->getHttpClient()->post(
                sprintf('/api/servers/%s/files/pull', $server->uuid),
                [
                    'json' => [
                        'url' => $url,
                        'root' => '/',
                        'file_name' => $fileName,
                        'use_header' => false,
                        'foreground' => true,
                    ],
                    'timeout' => self::PULL_TIMEOUT,
                ]
            );
        } catch (GuzzleException $e) {
            // cURL error 28: the panel stopped waiting, but Wings keeps downloading in the background and
            // leaves the archive in the server root without extracting it.
            if ($e instanceof TransferException && method_exists($e, 'getHandlerContext')
                && ($e->getHandlerContext()['errno'] ?? null) === 28) {
                throw new InstallException(sprintf(
                    'The node was still downloading %s after %d minutes, so the panel stopped waiting. ' .
                    'The download continues on the node: when %s appears in the Files tab, extract it there.',
                    $fileName,
                    intdiv(self::PULL_TIMEOUT, 60),
                    $fileName
                ));
            }
            throw new InstallException('Download failed: ' . $this->wingsMessage($e));
        }

        // decompressFile() has its own 15 minute timeout.
        try {
            $repo->decompressFile('/', $fileName);
        } catch (DaemonConnectionException $e) {
            // DaemonConnectionException wraps the Guzzle error; read Wings' own message from it when there is one.
            $prev = $e->getPrevious();
            $msg = $prev instanceof GuzzleException ? $this->wingsMessage($prev) : $e->getMessage();

            throw new InstallException('Extraction failed: ' . $msg);
        }

        // The archive is no longer needed and would otherwise use the server's disk quota (often hundreds of MB).
        // On a failed extraction it is kept so the user can inspect or extract it by hand.
        try {
            $repo->deleteFiles('/', [$fileName]);
        } catch (DaemonConnectionException $e) {
            // The pack is installed; a leftover archive is not worth failing the install for.
        }
    }

    /** Pull a human message out of a Wings error response (it uses "error" or "message"). */
    private function wingsMessage(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);
            $msg = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
            if ($msg) {
                return (string) $msg;
            }
        }

        return 'could not complete the transfer on the node. Please try again.';
    }
}
