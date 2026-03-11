# Thunder Client Generator — TODO

## Implementation Checklist

- [x] **Config**: Add `thunder_client` section to `src/config/openapi-docs.php`
- [x] **ThunderClientGenerator**: Core generator class (`src/Generators/ThunderClientGenerator.php`)
  - [x] Load OpenAPI JSON & existing collection
  - [x] Build folders from tags (fallback: path segment inference)
  - [x] Build requests (URL conversion, name, headers, auth, body)
  - [x] Auth handling (bearer, header, basic; multi-scheme → multiple requests)
  - [x] Request body construction from schema examples with `$ref` resolution
  - [x] No-overwrite merge logic
  - [x] Environment file generation (optional)
- [x] **ThunderClientFactory**: Factory wiring from config (`src/Generators/ThunderClientFactory.php`)
- [x] **ThunderClientCommand**: Standalone `openapi:thunder` command (`src/Console/Commands/ThunderClientCommand.php`)
- [x] **GenerateCommand**: Add `{--thunder-client}` flag to existing command
- [x] **ServiceProvider**: Register `ThunderClientCommand`
- [x] **Tests**: Unit tests (`tests/Unit/ThunderClientGeneratorTest.php`) — 36 tests, all passing
- [ ] **Integration**: Extend `FullPipelineTest.php` with `--thunder-client` test
- [x] **Verify**: All 89 tests pass (53 existing + 36 new)
