@php($idCheck = session('modpackinstaller_id_check'))
@if (is_array($idCheck))
  <div class="alert {{ $idCheck['ok'] ? 'alert-success' : 'alert-warning' }}">
    <strong>API key saved.</strong>
    @if ($idCheck['ok'])
      CurseForge confirmed the game and class IDs this extension uses: {{ $idCheck['message'] }}
    @else
      Warning: could not confirm the game and class IDs this extension uses
      (Minecraft = {{ $gameId }}, Modpacks = {{ $classId }}). {{ $idCheck['message'] }}
    @endif
  </div>
@endif
<form action="{{ $root }}" method="POST">
  <div class="box box-primary">
    <div class="box-header with-border">
      <h3 class="box-title">CurseForge Modpack Installer</h3>
    </div>
    <div class="box-body">
      <div class="form-group">
        <label for="api_key" class="control-label">CurseForge API key</label>
        <input
          type="password"
          id="api_key"
          name="api_key"
          class="form-control"
          placeholder="{{ $hasKey ? 'A key is saved. Leave blank to keep it.' : 'Paste your API key' }}"
          autocomplete="off"
        />
        <p class="text-muted small">
          Get a key from the CurseForge for Studios console. It is stored in the Blueprint database
          and only used server-side.
        </p>
      </div>
    </div>
    <div class="box-footer">
      {!! csrf_field() !!}
      {!! method_field('PATCH') !!}
      <button type="submit" class="btn btn-primary pull-right">Save</button>
    </div>
  </div>
</form>
