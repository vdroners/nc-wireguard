#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KIND="${1:-patch}"
for f in appinfo/info.xml package.json; do
  ver=$(grep -oP '(?<="version">)[0-9.]+(?=</version>)' "$ROOT/$f" 2>/dev/null || grep -oP '(?<="version": ")[0-9.]+' "$ROOT/$f")
  IFS=. read -r major minor patch <<< "$ver"
  case "$KIND" in
    major) major=$((major+1)); minor=0; patch=0 ;;
    minor) minor=$((minor+1)); patch=0 ;;
    *) patch=$((patch+1)) ;;
  esac
  new="${major}.${minor}.${patch}"
  sed -i "s/<version>${ver}<\\/version>/<version>${new}<\\/version>/" "$ROOT/appinfo/info.xml"
  sed -i "s/\"version\": \"${ver}\"/\"version\": \"${new}\"/" "$ROOT/package.json"
  echo "Bumped to $new"
done
