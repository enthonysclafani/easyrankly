#!/usr/bin/env bash
# Builds the distributable zip from the committed tree plus the compiled admin assets.
# Dev-only files are excluded through `export-ignore` in .gitattributes. build/ is not
# committed: it is compiled here with `npm run build` and added to the archive file by file
# (git archive --add-file needs git 2.35+ and keeps only the basename, hence one prefix per file).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${SKIP_NPM_BUILD:-}" != "1" ]; then
	npm ci --no-audit --no-fund
	npm run build
fi

args=()
while IFS= read -r file; do
	args+=( "--prefix=easyrankly/$(dirname "$file")/" "--add-file=$file" )
done < <(find build -type f | sort)

mkdir -p .dist
rm -f .dist/easyrankly.zip
git archive --format=zip "${args[@]}" --prefix=easyrankly/ -o .dist/easyrankly.zip HEAD
echo "Built .dist/easyrankly.zip"
