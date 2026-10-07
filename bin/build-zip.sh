#!/usr/bin/env bash
# Builds build/customer-history-by-currency-for-woocommerce.zip from the files that ship,
# leaving out everything listed in .distignore.
set -euo pipefail

slug="customer-history-by-currency-for-woocommerce"
root="$(cd "$(dirname "$0")/.." && pwd)"
stage="$(mktemp -d)"

rsync -a --exclude-from="$root/.distignore" "$root/" "$stage/$slug/"
mkdir -p "$root/build"
rm -f "$root/build/$slug.zip"
(cd "$stage" && zip -qr "$root/build/$slug.zip" "$slug")
rm -rf "$stage"

echo "build/$slug.zip"
