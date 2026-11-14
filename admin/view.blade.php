@if (!empty($keyRemoved))
  <div class="alert alert-success">
    <strong>API key removed.</strong> Search and installs are disabled until a new key is saved.
  </div>
@endif
@if (isset($idCheck) && is_array($idCheck))
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
          Apply for your own key with CurseForge (see the README, "Terms and compliance"). A key is personal:
          do not share it or reuse someone else's. It is stored in the panel database, only used server-side
          and never shown again.
        </p>
      </div>
      @if ($hasKey)
        <div class="checkbox">
          <label>
            <input type="checkbox" name="remove_key" value="1" /> Remove the saved key
          </label>
        </div>
      @endif
      <p class="text-muted small">
        This extension is not affiliated with or endorsed by CurseForge or Overwolf.
      </p>
    </div>
    <div class="box-footer">
      {!! csrf_field() !!}
      {!! method_field('PATCH') !!}
      <button type="submit" class="btn btn-primary pull-right">Save</button>
    </div>
  </div>
</form>
