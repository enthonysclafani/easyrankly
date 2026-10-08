#!/usr/bin/env bash
# Builds the distributable zip from the committed tree.
# Dev-only files are excluded through `export-ignore` in .gitattributes.
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p .dist
rm -f .dist/easyrankly.zip
git archive --format=zip --prefix=easyrankly/ -o .dist/easyrankly.zip HEAD
echo "Built .dist/easyrankly.zip"
