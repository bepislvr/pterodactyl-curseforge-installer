<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpackinstaller;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\CurseForgeClient;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

// Naming is fixed by Blueprint: namespace Pterodactyl\Http\Controllers\Admin\Extensions\{identifier},
// class {identifier}ExtensionController (routes/blueprint.php builds the class name from the identifier).
class modpackinstallerExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {
    }

    public function index(): View
    {
        return $this->page();
    }

    /**
     * @param array{status:string,message:string,ok:bool}|null $idCheck result of the ID check for this request only
     */
    private function page(?array $idCheck = null, bool $keyRemoved = false): View
    {
        return $this->view->make('admin.extensions.modpackinstaller.index', [
            'root' => '/admin/extensions/modpackinstaller',
            'blueprint' => $this->blueprint,
            'hasKey' => (string) $this->blueprint->dbGet('modpackinstaller', 'api_key') !== '',
            'gameId' => CurseForgeClient::GAME_ID_MINECRAFT,
            'classId' => CurseForgeClient::CLASS_ID_MODPACKS,
            'idCheck' => $idCheck,
            'keyRemoved' => $keyRemoved,
        ]);
    }

    public function update(modpackinstallerExtensionRequest $request, CurseForgeClient $curseForge): View|RedirectResponse
    {
        // The API terms require deleting every copy of the key when access ends, so it must be removable here.
        if ($request->boolean('remove_key')) {
            $this->blueprint->dbForget('modpackinstaller', 'api_key');

            return $this->page(null, true);
        }

        // The stored key is never sent back to the browser, so a blank field means "keep the current key".
        $key = trim((string) $request->input('api_key', ''));
        if ($key === '') {
            return redirect()->route('admin.extensions.modpackinstaller.index');
        }

        // Save first; the ID check only informs the admin and never blocks saving.
        $this->blueprint->dbSet('modpackinstaller', 'api_key', $key);

        // The result quotes names CurseForge returned. It is rendered in this response and not put in the session,
        // because data obtained through the API must not be saved (API terms 3.1).
        return $this->page($curseForge->checkIds($key)->toArray());
    }
}

class modpackinstallerExtensionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'api_key' => 'nullable|string|max:255',
            'remove_key' => 'nullable|boolean',
        ];
    }
}
