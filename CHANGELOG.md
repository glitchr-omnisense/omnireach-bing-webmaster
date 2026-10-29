# Changelog

## Unreleased (1.x)

### Fixed
- The API key, which Bing takes in the query string, no longer appears in an
  `UnreachableException`'s message (the fix is omnisense/omnireach's `JsonApi`; a test here keeps
  it so).
