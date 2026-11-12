<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpackinstaller;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

// NOTE: class and namespace naming follows Blueprint's admin controller template.
// Verify against the current Blueprint docs (see CLAUDE.md, "Unverified assumptions").
class modpackinstallerExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {
    }

    public function index(): View
    {
        return $this->view->make('admin.extensions.modpackinstaller.index', [
            'root' => '/admin/extensions/modpackinstaller',
            'blueprint' => $this->blueprint,
        ]);
    }

    public function update(modpackinstallerExtensionRequest $request): RedirectResponse
    {
        $this->blueprint->dbSet('modpackinstaller', 'api_key', (string) $request->input('api_key', ''));

        return redirect()->route('admin.extensions.modpackinstaller.index');
    }
}

class modpackinstallerExtensionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'api_key' => 'nullable|string|max:255',
        ];
    }
}
