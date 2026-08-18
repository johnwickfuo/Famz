#!/usr/bin/env bash
# Regenerates resources/fonts/*.woff2 from the upstream Google Fonts variable
# sources. Run this only when the type choices in docs/design-system.md change.
#
# Requires: python3 with `fonttools` and `brotli` (pip install fonttools brotli).
#
# Both faces are instanced (axes pinned/narrowed) and subsetted to the exact
# character set this platform needs: Latin + Latin-1 + Latin Extended-A, the
# Yoruba/Igbo dot-below vowels and combining tone marks, and the Naira sign.
# Splitting into unicode-range subsets is deliberately avoided: one request per
# face is cheaper than two on a patchy mobile connection.
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=resources/fonts
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

ARCHIVO_VF="https://fonts.gstatic.com/s/archivo/v25/k3kQo8UDI-1M0wlSTd7iL0nAMaM.ttf"
LEXEND_VF="https://fonts.gstatic.com/s/lexend/v26/wlptgwvFAVdoq2_F94zlCfv0bz1WCzsWzLdUgeGoQ4Q.ttf"

UNICODES="U+0020-007E,U+00A0-00FF,U+0100-017F,U+0192,U+01F8-01F9,U+0218-021B,\
U+0300-0304,U+0308,U+0309,U+030C,U+0323,U+0329,U+1E3E-1E3F,U+1E62-1E63,\
U+1EB8-1EB9,U+1ECA-1ECD,U+1EE4-1EE5,U+2010-2015,U+2018-201A,U+201C-201E,\
U+2020-2022,U+2026,U+2030,U+2039-203A,U+2044,U+2070,U+2074-2079,U+20A6,U+20AC,\
U+2116,U+2122,U+2190-2193,U+2212,U+2215,U+FEFF,U+FFFD"

FEATURES="kern,liga,ccmp,locl,mark,mkmk,case,tnum,lnum,zero,frac,numr,dnom,sups,subs,rvrn,aalt"

mkdir -p "$OUT"

# Display: Archivo pinned to the expanded width (wdth 125), weights 600-800.
curl -fsSL -o "$TMP/archivo.ttf" "$ARCHIVO_VF"
python3 -m fontTools.varLib.instancer "$TMP/archivo.ttf" wdth=125 wght=600:800 \
    --output="$TMP/archivo-exp.ttf" --no-overlap-flag >/dev/null
python3 -m fontTools.subset "$TMP/archivo-exp.ttf" --unicodes="$UNICODES" \
    --layout-features="$FEATURES" --flavor=woff2 --name-IDs='*' \
    --notdef-outline --desubroutinize --output-file="$OUT/archivo-expanded.woff2"

# Body: Lexend, weights 400-700.
curl -fsSL -o "$TMP/lexend.ttf" "$LEXEND_VF"
python3 -m fontTools.varLib.instancer "$TMP/lexend.ttf" wght=400:700 \
    --output="$TMP/lexend-i.ttf" --no-overlap-flag >/dev/null
python3 -m fontTools.subset "$TMP/lexend-i.ttf" --unicodes="$UNICODES" \
    --layout-features="$FEATURES" --flavor=woff2 --name-IDs='*' \
    --notdef-outline --desubroutinize --output-file="$OUT/lexend.woff2"

ls -l "$OUT"
