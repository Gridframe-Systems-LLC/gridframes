#!/bin/bash
# Fetch the MinIO client binary into the build context. MinIO withdrew mc from quay and dl.min.io;
# the pinned release's assets remain on GitHub, with a published sha256 to check against.
set -euo pipefail
VERSION=${MINIO_VERSION:-RELEASE.2025-08-13T08-35-41Z}
DIR="$(dirname "$0")/../docker/minio-mc"
mkdir -p "$DIR"
curl -fsSL -o "$DIR/mc" "https://github.com/minio/mc/releases/download/${VERSION}/mc.linux-amd64.${VERSION}"
curl -fsSL -o "$DIR/mc.sha256sum" "https://github.com/minio/mc/releases/download/${VERSION}/mc.linux-amd64.${VERSION}.sha256sum"
(cd "$DIR" && echo "$(cut -d' ' -f1 mc.sha256sum)  mc" | sha256sum -c - >/dev/null)
echo "mc ${VERSION} fetched and verified"
