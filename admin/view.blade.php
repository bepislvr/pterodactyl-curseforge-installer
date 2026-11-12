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
          value="{{ $blueprint->dbGet('modpackinstaller', 'api_key') }}"
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
