#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KIND="${1:-patch}"
ver=$(grep -oE '<version>[0-9]+\.[0-9]+\.[0-9]+</version>' "$ROOT/appinfo/info.xml" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
IFS=. read -r major minor patch <<< "$ver"
case "$KIND" in
  major) major=$((major+1)); minor=0; patch=0 ;;
  minor) minor=$((minor+1)); patch=0 ;;
  *) patch=$((patch+1)) ;;
esac
new="${major}.${minor}.${patch}"
sed -i "s/<version>${ver}<\\/version>/<version>${new}<\\/version>/" "$ROOT/appinfo/info.xml"
sed -i "s/\"version\": \"${ver}\"/\"version\": \"${new}\"/" "$ROOT/package.json"
if [ -f "$ROOT/package-lock.json" ]; then
  sed -i "0,/\"version\": \"${ver}\"/s//\"version\": \"${new}\"/" "$ROOT/package-lock.json"
  sed -i "0,/\"version\": \"${ver}\"/s//\"version\": \"${new}\"/" "$ROOT/package-lock.json"
fi
if grep -q "version-${ver}" "$ROOT/README.md" 2>/dev/null; then
  sed -i "s/version-${ver}/version-${new}/" "$ROOT/README.md"
fi
if [ -f "$ROOT/CHANGELOG.md" ] && ! grep -q "^## \[${new}\]" "$ROOT/CHANGELOG.md"; then
  awk -v v="$new" -v d="$(date +%F)" 'BEGIN{done=0} /^## \[/ && !done {print "## [" v "] - " d "\n"; done=1} {print}' \
    "$ROOT/CHANGELOG.md" > "$ROOT/CHANGELOG.md.tmp" && mv "$ROOT/CHANGELOG.md.tmp" "$ROOT/CHANGELOG.md"
fi
echo "Bumped ${ver} -> ${new}"
